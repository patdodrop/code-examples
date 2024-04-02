<?php
require_once( 'HTTP/Request.php' );

class WOTCWMSFormStep extends ProcessStep {  
//
// Created by Patrick J. DeCrescenzo
// Must have the connection to the MySQL server and have the client database selected before calling any methods of this class.

	var $defaultActor 	= 'admin';
	var $WOTCEligible   = '';
	public $WMSClientId;
	public $updateStatus;
	
	function __contsruct( $processID ) {
	// Constructor method overridden in order to assign the client specific WMS codes.
	//
	// Parameters received: processID - integer, id of the process the process step is to be associated with
	// Parameters returned: None

		parent::__construct( $processID );
		// need to add client config value
		$this->WMSClientId 	= getClientConfigValue( 'WMS_CLIENT_ID' );
		
		return;			
	}
	
	function applicationDetail( $application, $requisition, $process ) {
	// Displays the application detail iWindow for the current process step.
	//
	// Parameters received: application - Application object, application currently being evaluated
	// 						requisition - Requisition object, requisition currently being evaluated (needed to determine flagged application)
	// 						process - Process object, process the process step is associated with
	// Parameters returned: None
		$resumeWindow = new iWindow( 780, 550 );
		$resumeWindow->title  = $this->name . ' - ' . $application->jobSeeker->firstName . ' ' . $application->jobSeeker->lastName;
		$resumeWindow->title .= ' ( ' . $application->requisition->name . ( ( $application->requisition->id == $requisition->id ) ? '' : ' - <span styl="color: #F30;">Flagged Application</span>' ) . ' )';
		$resumeWindow->assignMenuSection( array( $this, 'applicationDetailMenu' ), array( $application, $requisition, $process ), 30 );
		$resumeWindow->assignMainContent( array( $this, 'applicationDetailMain' ), array( $application, $requisition, $process ) );
		$resumeWindow->assignFooterSection( array( $this, 'applicationDetailFooter' ), array( $application, $requisition, true ), 30 );
		
		// Add JavaScript to be executed that will reload the parent window when iWindow is closed. Done to update any altered application information.
		$resumeWindow->addCloseScript( 'parent.document.pageForm.submit();' );
		
		$resumeWindow->displayiWindow();
		
		return;
	}

    function applicationDetailMain( $application, $requisition, $process ) {
    // Displays detailed information specific to process step for main section of the application step detail iWindow.
    // Called via call_user_func_array from the iWindow class.
    //
    // Parameters received: application - Application object, application currently being evaluated
    // 						requisition - Requisition object, requisition currently being evaluated (needed to determine flagged application)
    // Parameters returned: None
        if ( !$this->isStarted ) {
            parent::applicationDetailMain( $application, $requisition );
        } else {
             ?>
            <div class="innerAll">
                <div class="widget widget-body-white">
                    <div class="widget-body">
                        <?php $this->applicationDetailHeader( $application, $requisition ); ?>
                    </div>
                </div>
                <div class="widget widget-body-white">
                    <div class="widget-body">
                        <div class="innerAll">
                            <?php $this->displayAdminApplicationStep( $application, $requisition ); ?>
                            <br /><br />
                        </div>
                    </div>
                </div>
            </div>
            <?php
        }	
    }

    function displayAdminApplicationStep( $application, $requisition ) {
    //Displays the Form link to WMS to print the 8850 Form
    //Also displays the done button to complete the process

        //$this->WMSClientId 	= getClientConfigValue( 'WMS_CLIENT_ID' );

        $query 	= 'SELECT adp_location_id, wms_client_code FROM client.adp_locations WHERE location_id=' . $application->requisition->location->id;
        $result = sql_query( $query );

        // If there is no location code associated with the location, then this step cannot be used.
        if ( !( $row = mysql_fetch_assoc( $result ) ) ) {
           $this->isValid = false;

           echo "no wms location id for location->" . " " . $application->requisition->location->id;
           $email_ssn = $ssn;
           $email_ssn = substr_replace($email_ssn, "XXXXX", 0, 5);
           $newLine = "\r\n";
           $notificationText  = 'no wms location id for location->' . " " . $application->requisition->location->id . $newLine . $newLine;
           $notificationText .= '  WMS Location ID = ' . $this->WMSLocID . $newLine;
           $notificationText .= '  Location ID = ' . $application->requisition->location->id . $newLine;
           $notificationText .= '  Name = ' . $application->jobSeeker->firstName . " " .  $application->jobSeeker->lastName ;
           $notificationText .= '  Applicant SSN = ' . $email_ssn . $newLine . $newLine;
           $notificationText .= '  Hire Status = ' . $hireStatus . $newLine . $newLine;
           $notificationText .= 'The request was posted to ' . $wmsURL;

           require_once( '../classes/Email.inc' );

           $mailItem2 = new Email();
           $mailItem2->subject = 'WMS WOTC Post Information - New Hire Update';
           $mailItem2->to = SYSTEM_NOTIFICATION_EMAIL; 
                   $mailItem2->mime->setTXTBody( $notificationText );
           $mailItem2->sendEmail(); 

           exit;
        } else {			
                $this->WMSLocID    = $row['adp_location_id'];
                if ( !empty( $row['wms_client_code'] ) ) {
                        $this->WMSClientId = $row['wms_client_code'];
                } else {
                        $this->WMSClientId 	= getClientConfigValue( 'WMS_CLIENT_ID' );	
                }		
        }

        $wmsURL = 'https://www.waltonmgt.com/netcentives/questionnaire/gateway.jsp';

        //Retrieve the new $formsLink URL from wotc table.		
        $query 	= 'SELECT report_url FROM client.adp_wotc WHERE application_id=' . $application->id;
        $result = sql_query( $query );
        if ( ( $row = mysql_fetch_assoc( $result ) ) ) {
            $formsLink = $row['report_url'];
        }	

        if ( $formsLink === '1' ) {
            $formsLink = ''; 
        }	

        if ( !empty( $formsLink ) ) {
            $formsLink = $formsLink . '&EmpLocation=' . $this->WMSLocID . '&NHUpdate=1'; 
        }	

        $ssn = $application->jobSeeker->ssn->getDecrypted();
        //URL used for 8850 form


        // Only display link to forms if this step has been started.
        $this->checkEligibility( $application );

        // send all hires to walton using HTTP_Request $request
        if ( $this->isStarted ) {		
                // If process step is not complete, transition it.
                if  ( $this->status == 0 ) {							

                        $request = new HTTP_Request( $wmsURL );
                        $request->setMethod( 'POST' );
                        $request->addHeader( 'Content-type', 'application/x-www-form-urlencoded' );
                        $request->addPostData( 'CustID', $this->WMSClientId );
                        $request->addPostData( 'SSN', $ssn );
                        $request->addPostData( 'LName', $application->jobSeeker->lastName );
                        $request->addPostData( 'EmpLocation', $this->WMSLocID );
                        $request->addPostData( 'NHupdate', 1 );
                        if ( !PEAR::isError( $request->sendRequest() ) ) {
                           $response = utf8_encode( $request->getResponseBody() );
                           $hireStatus = $this->updateHireStatus( $application );
                           $newLine = "\r\n";
                           $email_ssn = $ssn;
                           $email_ssn = substr_replace($email_ssn, "XXXXX", 0, 5);
                           $notificationText  = 'The New Hire Update success' . $newLine . $newLine;
                           $notificationText .= '  WMS Location ID = ' . $this->WMSLocID . $newLine;
                           $notificationText .= '  Location ID = ' . $application->requisition->location->id . $newLine;
                           $notificationText .= '  Name = ' . $application->jobSeeker->firstName . " " .  $application->jobSeeker->lastName ;
                           $notificationText .= '  Applicant SSN = ' . $email_ssn . $newLine . $newLine;
                           $notificationText .= '  Hire Status = ' . $hireStatus . $newLine . $newLine;
                           $notificationText .= 'The request was posted to ' . $wmsURL . $newLine . $newLine;
                           $notificationText .= '---- First time sending Post variables below ----' . $newLine . $newLine;
                           $notificationText .= 'Client ID-> ' .  $this->WMSClientId . $newLine . $newLine;
                           $notificationText .= 'SSN-> ' . $ssn . $newLine . $newLine;
                           $notificationText .= 'Last Name-> ' . $application->jobSeeker->lastName . $newLine . $newLine;
                           $notificationText .= 'Location ID-> ' . $this->WMSLocID . $newLine . $newLine;
                           $notificationText .= 'NHupdate = 1' . $newLine . $newLine;
                           $notificationText .= 'Form link->  ' . $formLink;

                           require_once( '../classes/Email.inc' );

                           $mailItem2 = new Email();
                           $mailItem2->subject = 'WMS WOTC Post Information';
                           $mailItem2->to = SYSTEM_NOTIFICATION_EMAIL; 
                           $mailItem2->mime->setTXTBody( $notificationText );
                           $mailItem2->sendEmail(); 
                           $this->isStarted = false;
                        } else {
                                die('request failed');
                                $email_ssn = $ssn;
                                $email_ssn = substr_replace($email_ssn, "XXXXX", 0, 5);
                                $newLine = "\r\n";
                                $errorResponse   = 'The New Hire Update failed' . $newLine . $newLine;
                                $errorResponse  .= 'WMS Location ID = ' . $this->WMSLocID . $newLine;
                                $errorResponse  .= 'Location ID = ' . $application->requisition->location->id . $newLine;
                                $errorResponse  .= 'Name = ' . $application->jobSeeker->firstName . " " .  $application->jobSeeker->lastName ;
                                $errorResponse  .= 'Applicant SSN = ' . $email_ssn . $newLine . $newLine;
                                $errorResponse  .= 'Hire Status = ' . $hireStatus . $newLine . $newLine;
                                $errorResponse  .= 'The request was posted to - failed ' . $wmsURL;

                                require_once( '../classes/Email.inc' );

                                $mailItem2 = new Email();
                                $mailItem2->subject = 'WMS WOTC Post Information';
                                $mailItem2->to = SYSTEM_NOTIFICATION_EMAIL; 
                                $mailItem2->mime->setTXTBody( $errorResponse );
                                $mailItem2->sendEmail(); 
                                $this->isStarted = false;							
                        }
                }	
        }

        switch ( $this->WOTCEligible ) {
            case '0':
                //Not eligible case
                if  ( $this->status == 0 ) {
                    $this->displayDoneButton( $application, $requisition, $formsLink );							
                } elseif ( $this->status == 1 ) {
                    ?>
                    <p>You have completed this step.</p>
                    <br />
                    <p><strong>Not WOTC eligible.</strong></p>
                    <?php
                    if ( !empty( $formsLink ) ) {
                        ?>
                        <p><a href="<?= $formsLink ?>" target="_blank">Print/Download Form</a></p>
                        <?php
                    }
                }
                break;

            case '1':
                // Eligible case
                if  ( $this->status == 0 ) {
                    ?>
                    <p><strong>WOTC eligible.</strong></p>
                    <p>Please click the Print/Download 8850 Form button to continue the process.</p>
                    <?php
                    $this->displayDoneButton( $application, $requisition, $formsLink );
                } elseif ( $this->status == 1 ) {
                    ?>
                    <p><strong>You have completed this step.</strong></p>
                    <?php
                    $this->displayDoneButton( $application, $requisition, $formsLink );
                }	
                break;

            case '2':
                //Opted Out
                if  ( $this->status == 0 ) {
                    $this->displayDoneButton( $application, $requisition, $formsLink );															
                } elseif ( $this->status == 1 ) {
                    ?>
                    <p>You have completed this step.</p>
                    <br />
                    <p><strong>Opted out.</strong></p>
                    <p><a href="<?= $formsLink ?>" target="_blank">Print/Download Form</a></p>
                    <?php
                }	
                break;
        }										
    }
	   
 	function checkEligibility( $application ) {
	// checks to see if applicants eligibility for WOTC forms
	//
	// Parameters received: application - Application object, application currently being evaluated
	// Parameters returned: $wotcStatus - integer, status of process step
		$wotcStatus	= 0; // integer, Status of process step.
		// do we need to checked if they are hired?? 

		$query 	= 'SELECT * FROM client.adp_wotc 
				   WHERE application_id=' 	. $application->id;
				   
		$result = sql_query( $query );
		
		if ( $row = mysql_fetch_assoc( $result ) ) {			
			$this->WOTCEligible = $row['wotc_eligible'];
		}

		return $this->WOTCEligible;
	}

 	function updateHireStatus( $application ) {
	// Updates information related to the WMS WOTC process step. this is populated when new hire update is true and sent to WMS
	// status from WMS using query string post.
	//
	// Parameters received: application - Application object, application currently being evaluated
	// Parameters returned: $wotcStatus - integer, status of process step
		
		$this->updateStatus	= '"New Hire Updated"';	
		// Update wotc status. need to create a new table for WMS WOTC - (wms.wotc)
		$query 	= 'INSERT INTO client.adp_wotc 
				   SET application_id='	. $application->id . ', 
				   	   screening_status=' . $this->updateStatus . ' 
				   ON DUPLICATE KEY UPDATE screening_status=' . $this->updateStatus;				   			   
		if ( sql_query( $query ) ) {
			$status = 1;
		}
		return $status;
	}

     // Displays the Admin done form that allows the admin to complete the step
     // It passes values back to Walton and then pops up a form to print or a message.
     // @param object application active application
     // @return void
    function displayDoneButton( $application, $requisition, $formsLink ){
        
        if ( $this->WOTCEligible === '2' ) {
            ?>
            <p><strong>Opted out.</strong></p>
            <p>Please click the Done button to continue the process.</p>
            <?php
        } elseif ( $this->WOTCEligible === '0' ) {
            ?>
            <p><strong>Not WOTC eligible.</strong></p>
            <p>Please click the Done button to continue the process.</p>
            <?php
        }
        ?>						
        <br />
        <form name="wms" method="post" action="application_actions.php">
            <?php postSessionInfo(); ?>
            <input type="hidden" name="appAction" value="step_details" />
            <input type="hidden" name="subAction" value="view" />
            <input type="hidden" name="apps" value="<?= $application->id ?>" />
            <input type="hidden" name="rid" value="<?= $requisition->id ?>" />
            <input type="hidden" name="pid" value="<?= $this->processID ?>" />
            <input type="hidden" name="sid" value="<?= $this->id ?>" />
            <input type="hidden" name="wmsCheckComplete" value="0" />
            <?php
            if ( $this->WOTCEligible === '1' ) {																
                //for prior applicants with no new printlink data							
                if ( empty( $formsLink ) ) {
                    if ( !$this->isComplete ) {
                        ?>
                        <div align="center">
                            <button type="button" class="btn btn-ps-default" id="submitButton" name="submitButton" onclick="document.wms.wmsCheckComplete.value='1'; completeStep(this);" value="Done">Done</button>
                        </div>
                        <?php
                    } else {
                        ?>
                        <p><strong>This applicant may be WOTC eligible.</strong></p>
                        <p>To determine eligibility, contact the Walton Management IT Department at: 732-531-7117 and provide the applicant's name.</p>
                        <?php
                    }	 
                } else {
                    ?>
                    <div align="center">
                        <button type="button" class="btn btn-ps-default" id="submitButton" name="submitButton" onclick="document.wms.wmsCheckComplete.value='1'; completeFormStep( this, '<?= $formsLink ?>' );" value="Print/Download 8850 Form">Print/Download 8850 Form</button>
                    </div>
                    <?php
                }
            } else {
                ?>
                <div align="center">
                    <button type="button" class="btn btn-ps-default" id="submitButton" name="submitButton" onclick="document.wms.wmsCheckComplete.value='1'; completeFormStep( this, '<?= $formsLink ?>' );" value="Done">Done</button>
                </div>
                <?php
            }	
            ?>
            <script type="text/javascript">
                function completeFormStep( theButtonPushed, Link ) {
                    var submitLock;
                    window.open(Link, "_blank");
                    if ( submitLock ) 
                    {
                        return false;
                    } 
                    else
                    {
                        submitLock = true;
                        theButtonPushed.disabled = true;
                        theButtonPushed.form.submit();
                        //deactivateiWindow('iWindowFrame');
                        return;
                    }						
                }

                function completeStep( theButtonPushed ) {
                    var submitLock;
                    if ( submitLock ) 
                    {
                        return false;
                    } 
                    else
                    {
                        submitLock = true;
                        theButtonPushed.disabled = true;
                        theButtonPushed.form.submit();
                        //deactivateiWindow('iWindowFrame');
                        return;
                    }						
                }
            </script>
        </form>
        <?php	
        if ( $_POST['wmsCheckComplete'] === '1' ) {		 	
            if ( !$this->isComplete ) {
                parent::statusProcessStep( $application->id, $stepStatus=1 );
                $this->transitionProcessStep( $application );
                ?>
                <script type="text/javascript">window.close();</script>
                <?php
            }
        }			
    }
}
