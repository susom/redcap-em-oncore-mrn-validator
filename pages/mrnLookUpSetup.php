<?php
namespace Stanford\OnCoreMrnValidator;
/** @var  \Stanford\OnCoreMrnValidator\OnCoreMrnValidator $module **/


$mrn_endpoint_url = $module->getUrl("pages/mrnLookUp.php", true, true);
echo "<h5>This is the MRN Validator Endpoint to use with a POST call</h5><br><br><div style='font-size: 11pt'>" . $mrn_endpoint_url . "</div><br><br><br>";


?>


