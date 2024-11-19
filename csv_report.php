#!/usr/bin/php
<?php
//Monthly Applicant Count report for all live clients

//Parameters needed: 1.Enterprise ID or all - 2.Client ID or all - 3.Start Date - 4.End Date
//Date format 0000-00-00

    chdir( __DIR__ );
    require_once( '../include/includes.php' );
    
    // Retrieve and validate arguments entered at the command line.
    $start_date = $argv[3];
    $end_date   = $argv[4];
   
    $enterpriseList = loadEnterpriseList( $argv[1] );
    foreach ( $enterpriseList as $enterpriseId ) {
        $enterprise = new Enterprise();
        if ( !$enterprise->loadEnterprise( $enterpriseId ) ) {
            echo 'Invalid enterprise id : ' . $enterpriseId . "\n\n";
            echo '============================================================' . "\n\n";
        } else {
            echo 'Running workflow update script on ' . $enterprise->name . ' (' . $enterprise->id . ')' . "\n\n";

            // Perform updates to system database.
            $errorList = array();
            $data .= "\r Enterprise: " . $enterprise->name . "\r\n ,Month-Year, Count, \r";

            $clientList = loadClientList( $argv[2] );
            foreach ( $clientList as $clientId ) {
                $client = new Client();
                $client->loadClient( $clientId );
                if ( !$client->validClient ) {
                    echo '  -  Invalid client id : ' . $clientId . "\n";
                } else {
                    // Table updates require creator privileges.
                    $enterprise->creatorDatabaseConnect();
                    // Export Client Data from all clients
                    $errorList = array();
                }

                $resultFile = 'client_application_counts.csv';

                $lhandle = fopen($resultFile, 'w');
                $lhandle = fopen($resultFile, 'a+');
                // Open files for writing
                if (!$lhandle) {
                    mail('email_address@gmail.com', 'Data Export Can Not Open File', 'Data Export Can Not Open File');                           
                    echo "\n\nCannot open file ( " . $resultFile . " )\n\n";
                }

                $query = "SELECT COUNT(*) as count
                               FROM client.applications a 
                               WHERE date(a.application_date) >= '" . $start_date . "'
                               AND date(a.application_date) <= '" . $end_date . "';";
                $counts = array();
                $result = sql_query( $query );
                if ( mysql_num_rows( $result ) > 0 ) {
                    while ( $row = mysql_fetch_assoc( $result ) ) {
                        $run_date = '"' . date( 'F-Y', strtotime( $start_date ) ). '",';                        
                        $client->name = str_replace( '--', '', $client->name );
                        $client->name = str_replace( ',', '', $client->name );
                        $data .= $client->name;
                        $data .= ',' . "'" . $run_date . "" . implode('","', $row) . "\r\n";   
                        $total += $row['count'];
                    }
                }
            }                    
        }		
    }   

    $data_top .= 'Total Applicant Count - ' . $total;
    $data_top .= "\r\n";
    
   
    fwrite( $lhandle, $data_top );   
    fwrite( $lhandle, $data );
    fclose( $lhandle );
    sendEmailReport( $resultFile, $run_date );                    

    return;

    function loadClientList( $clientArgument ) {
    // Loads the list of clients associated with the given client argument.
    //
    // Parameters received: clientArgument - string, argument entered at command line when calling this script
    // Parameters returned: clientList - array, list of client id's resulting from passed clientArgument
            $clientList = array();

            // Query is performed on all parameter possibilities for validation.
            // If a value of 'all' was entered for the clientArgument, then all clients will be updated.
            if ( $clientArgument == 'all' ) {
                $query = 'SELECT client_id FROM system.client c WHERE c.is_active = 1 ORDER BY client_id';
            } else {
                $query = 'SELECT client_id FROM system.client c WHERE c.is_active = 1 AND client_id=' . intval( $clientArgument ) . ' ORDER BY client_id';
            }

            $result = sql_query( $query );

            while ( $row = mysql_fetch_assoc( $result ) ) {
                $clientList[] = $row['client_id'];
            }

            return $clientList;
    }

    function loadEnterpriseList( $enterpriseArgument ) {
    // Loads the list of enterprises associated with the given enterprise argument.
    //
    // Parameters received: enterpriseArgument - string, argument entered at command line when calling this script
    // Parameters returned: enterpriseList - array, list of enterprise id's resulting from passed enterpriseArgument
            $enterpriseList = array();

            // Need to instantiate an Enterprise object in order to have the centralDataConnect function defined.
            $enterprise = new Enterprise();
            centralDatabaseConnect();

            // Query is performed on all parameter possibilities for validation.
            // If a value of 'all' was entered for the enterpriseArgument, then all enterprises will be updated.
            if ( $enterpriseArgument == 'all' ) {
                $query = 'SELECT enterprise_id FROM central.enterprise ORDER BY enterprise_id';
            } else {
                $query = 'SELECT enterprise_id FROM central.enterprise WHERE enterprise_id=' . intval( $enterpriseArgument ) . ' ORDER BY enterprise_id';
            }

            $result = sql_query( $query );

            while ( $row = mysql_fetch_assoc( $result ) ) {
                $enterpriseList[] = $row['enterprise_id'];
            }

            return $enterpriseList;
    }

    function sendEmailReport( $resultFile, $run_date ) {

            $mailSubject = 'Applicant Counts for ' . $run_date;
            $mailBody = 'All Active clients';
          
            $mailItem = new Email();
            $mailItem->subject = $mailSubject;
            $mailItem->to = 'support@gmail.com';
            $mailItem->mime->setTXTBody( $mailBody );
            $mailItem->addAttachment( $resultFile, 'application/vnd.ms-excel' );
            $mailItem->sendEmail();
    }

?>
