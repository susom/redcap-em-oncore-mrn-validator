<?php
namespace Stanford\OnCoreMrnValidator;
/** @var \Stanford\OnCoreMrnValidator\OnCoreMrnValidator $module */

use \Exception;
use \GuzzleHttp;

require_once $module->getModulePath() . "/classes/SubjectDemographics.php";

// Retrieve the ID token used to retrieve demographics from the MRN endpoint
define ('ID_TOKEN', 'id');

// Map the demographics we receive to the demographics in the SubjectDemographics class
$currentMappings = array(
    // STARR fieldname      // OnCore function
    "mrn"                   => "setMrn",
    "birthDate"             => "setBirthDate",
    "firstName"             => "setFirstName",
    "lastName"              => "setLastName",
    "gender"                => "setGender",
    "canonicalEthnicity"    => "setEthnicity",
    "canonicalRace"         => "setRace"
);

// Retrieve body of message
$body = file_get_contents('php://input');
$params = json_decode($body, true);
$action = $params["action"];

// Make sure the shared secret matches what we are expecting
$stored_secret = $module->getSystemSetting('shared-secret');
if (empty($params["secret"])) {
    $module->emError("The shared secret is empty. Please enter one into the config.json file and try again");
    header("HTTP/1.1 401 Unauthorized");
    return;
} elseif ($stored_secret <> $params["secret"]) {
    $module->emError("The shared secret does not match");
    $module->emDebug("From system params: " . $stored_secret . ", and from API: " . $params["secret"]);
    header("HTTP/1.1 403 Forbidden");
    return;
}

// Validate input
if ($action != 'validate' and $action != 'demographics') {
    header("HTTP/1.1 206 Partial Content");
    return;
} else {
    $mrnList = explode(',', $params["mrns"]);
    if (empty($mrnList)) {
        header("HTTP/1.1 206 Partial Content");
        return;
    }
}

// Make the API call to the MRN LookUp endpoint to retrieve subject information for each entered MRN
try {
    $returnData = getSubjectInformation($mrnList);
} catch (Exception $ex) {
    $module->emError("Exception when sending API call to Identifer API Endpoint: $ex");
    header("HTTP/1.1 500 Internal Server Error");
    return;
}

/*
$returnData = array("11111111" =>
                array(
                    "mrn" => "11111111",
                    "birthDate" => "1900-01-01T00:00:00-08:00",
                    "firstName" => "HOLD",
                    "lastName"  => "PATIENT",
                    "gender" => "Unknown",
                    "canonicalEthnicity"    => "Non-Hispanic",
                    "canonicalRace" => "Unknown",
                    "zip"   => "94564"
                    ),
                "12345678" =>
                    array(
                        "mrn"   => "12345678",
                         "birthDate"    => "1986-01-23T00:00:00-08:00",
                         "firstName"    => "SANITY",
                         "lastName"     => "CADTEST",
                         "gender"       => "Female",
                         "canonicalEthnicity"   => "Unknown",
                         "canonicalRace"    => "Unknown",
                         "zip"          => "91023"
                    )
                );
*/

// Set the flag to say we want all demographics or just the validation of whether or not this MRN is valid
$getDemographics = ($action == 'demographics');

// Retrieve data for each MRN in the list. Retrieve either all demographic information or just true/false for validation
$subjects = array();
foreach($mrnList as $mrn) {
    $demo = getSubjectDemographics($mrn, $returnData, $getDemographics, $currentMappings);
    $subjects[$mrn] = $demo->getFilteredArray();
}

print json_encode($subjects);


/**
 * This function retrieves a Vertx token which is required to use the Identifier API Endpoint.
 *
 * @return array - return token as first parameter and url endpoint as second parameter
 */
function getMRNVerificationToken() {

    global $module;

    // Retrieve a token that we need to access the Identifier Endpoint.  We retrieve it from
    // the vertx token manager.
    $token = '';
    try {
        $VTM = \ExternalModules\ExternalModules::getModuleInstance('vertx_token_manager');
        $token = $VTM->findValidToken(ID_TOKEN);
        $url = $VTM->getAPIEndpoint(ID_TOKEN);
    } catch (Exception $ex) {
        // TODO: How should we handle errors?
        $module->Error("Could not retrieve Vertx Token: " . $ex->getMessage());
    }

    return [$token, $url];
}


/**
 * This function will make the URL call to retrieve demographics data for each subject
 *
 * @param $mrnList
 * @return array|false
 * @throws GuzzleHttp\Exception\GuzzleException
 */
function getSubjectInformation($mrnList) {

    global $module;

    $demographics = array();

    // Retrieve vertx token and API Endpoint URL
    [$token, $url] = getMRNVerificationToken();
    if (!empty($token) and !empty($url)) {

        // Setup the request body and header
        $body = array(
            "mrns" => $mrnList
        );
        $header = array("Authorization" => "Bearer " . $token,
            "Content-Type" => "application/json");

        // Call API Endpoint
        $data = array();
        try {
            // Call the MRN Verfication Endpoint
            $client = new GuzzleHttp\Client;
            $resp = $client->request('POST', $url, [
                GuzzleHttp\RequestOptions::SYNCHRONOUS => true,
                GuzzleHttp\RequestOptions::HEADERS => $header,
                GuzzleHttp\RequestOptions::BODY => json_encode($body)
            ]);

            $returnStatus = $resp->getStatusCode();
            if ($returnStatus <> 200) {
                $module->emError("HTTP Return Code is: $returnStatus");
                return false;
            } else {
                // everything worked so retrieve the returned demographics
                $data = $resp->getBody()->getContents();
            }

        } catch (Exception $ex) {
            $module->emError("Exception calling endpoint: " . $ex);
        }

        $returnedData = json_decode($data, TRUE);

        // Reformat to set the MRN as the key for faster access
        foreach ($returnedData["result"] as $demo) {
            $mrn = $demo["mrn"];
            $demographics[$mrn] = $demo;
        }
    }

    return $demographics;
}

/**
 * This function pulls out the demographic data we are interested in from the API call. This
 * function will either pull out the demographics information listed in the currentMappings
 * array or if getDemo is false, it will return true or false if the MRN is valid.
 *
 * @param $mrn
 * @param $returnData
 * @param $getDemo
 * @return SubjectDemographics
 */
function getSubjectDemographics($mrn, $returnData, $getDemo, $currentMappings) {

    global $module;
    $subject = array();

    // Use the SubjectDemographics class which can validate each field if needed.
    try {
        $validFields = true;
        $subject = new SubjectDemographics($validFields);
    } catch (Exception $ex) {
        $module->emError("Cannot instantiate SubjectDemographics");
    }

    // Retrieve the mrns that we received data for
    $returnedMrns = array_keys($returnData);
    $subject->setMrn($mrn);
    if (!in_array($mrn, $returnedMrns)) {

        // This MRN is not in the return message from STARR so it is invalid
        $subject->setMRNValid(false);

    } else {

        // We received the MRN back from STARR so it is valid.
        $subject->setMRNValid(true);
        if ($getDemo) {
            $thisSubject = $returnData[$mrn];
            foreach ($currentMappings as $starrField => $oncoreFunc) {
                $subject->$oncoreFunc($thisSubject[$starrField]);
            }
        }
    }

    return $subject;
}

