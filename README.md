# OnCore MRN Validator
This EM will be called by the OnCore Integration EM with a list of MRNs to validate
and/or retrieve Demographics data.

This EM uses the Token Manager EM to retrieve a valid token for the ID endpoint
and the IRB LookUp EM to verify the IRB is active before verifying IRBs.
