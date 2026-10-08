Set of scripts to keep track of QSOs made from parks (WWFF, POTA, SOTA).
All of this creates a HAM Radio QSO Log dedicated for enthusiats of park activations.

BASIC FUNCTIONS OF THE UPLOAD PROCESS
 - upload page is login-secured
 - the process renders the ADIF files and inserts QSO records to the MySQL database
 - each upload is registered and can be deleted (together with related QSOs)
 - references (WWFF, POTA, SOTA, GRID Locator) are in general rendered from the ADIF file
 - references can be manually eneterd on the upload form (form-entered values take precedence)
 - upload process proctecs from loading duplicated logs/records
 - according to WWFF/POTA award rules only one WWFF reference can be eneterd but many POTA references for one QSO

BASIC FUNCIONS OF THE QSO SEARCH
 - searching for callsign (mandatory) 
 - optional search filters (Date from/to, WWFF, POTA, SOTA)
 - for each found QSO the page displays WWFF, POTA, SOTA and PGA (POlish Gmina Award) references
 - some basic statistics are shown for found records
 - some basic statistics are shown for the whole log database
