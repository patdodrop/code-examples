<?php
require_once( 'HTTP/Request.php' );

class WOTCWMSScreeningStep extends ProcessStep {
//
// Created by Patrick J. DeCrescenzo
//
// Work Opportunity Tax Credit Screening by Walton Management process step class.
// Contains and handles linkage to Walton Management WOTC screening accessed by job seeker.
// This will involve taking the applicant to the WMS WOTC screening system, retrieving values from the WMS system, and 
// returning them to the Selectech system.
//
// Must have the connection to the MySQL server and have the client database selected before calling any methods of this class.
//
	// Attributes specific to this process step related to job seeker application process.
	var $dataFields		= array();		// array, List of data fields needed to process WMS WOTC.
	var $errorList	 	= array();		// array, List of error messages to display to job seeker.
	var $isLoaded 		= false;		// boolean, Indicator that the data needed to perform the WMS WOTC has been loaded.
	var $wotcStage 		= 'collection'; // string, Stage the WOTC process step is at (data collection, transfer to WMS or returning from vendor site).

	// Attributes specific to this process step.
//	var $WMSClientId; 										// string, WMS company identifier.
	var $WMSLocID;											// adp_location_id really is WMS location ID
	var $isWMSStaging;										// boolean, Indicator that the WMS staging environment is being used, not production.
	var $reportURL 	= '';	    							// string, URL to use for administrator to access WMS 8850 form.
	var $screeningStatus; 									// string, Screening status returned from WMS.
	var $securityCode; 										// string, Security code used to for interaction with WMS in information requests.
	var $validStatuses 	= array( 'ALREADY_HIRED', 'OK' ); 	// array, List of screening statuses returned from WMS that are considered valid.
	var $WOTCEligible 	= 0;   						    	// boolean, Indicator of applicant eligibility for Work Opportunity Tax Credit (WOTC).
	
        public $jobSeeker; 
        public $application;	
	public $transferURL;
	public $WMSClientId;
	public $formsLink;
	public $formsLinkExt;
	
	function __construct( $processID ) {
	// Constructor method overridden in order to assign the client specific ADP codes.
	//
	// Parameters received: processID - integer, id of the process the process step is to be associated with
	// Parameters returned: None

      parent::__construct( $processID );
      $this->WMSClientId 	= getClientConfigValue( 'WMS_CLIENT_ID' );
      return;			
	}

    function applicationDetailMain( $application, $requisition ) {
    // Displays detailed information specific to process step for main section of the application step detail iWindow.
    // Called via call_user_func_array from the iWindow class.
    //
    // Parameters received: application - Application object, application currently being evaluated
    // 						requisition - Requisition object, requisition currently being evaluated (needed to determine flagged application)
    // Parameters returned: None
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
                        <?php
                        if( $this->isComplete ) {
                            switch ( $this->WOTCEligible ) {
                                case '0':
                                    $eligibility = 'Not eligible';
                                    break;

                                case '1':
                                    $eligibility = 'Eligible';
                                    break;

                                case '2':
                                    $eligibility = 'Opted Out';
                                    break;
                                    
                            }										
                            // use this instead of reportURL $this->screeningStatus
                            if ( empty( $this->screeningStatus ) ) {
                                $eligibility .= '<br /><br /><strong>Note:</strong> Eligibility is based upon a preliminary evaluation. Further examination of the applicant\'s information may change the eligibility status.';
                            }	
                        } else {
                            $eligibility = '';
                        }
                        ?>
                        <form name="formatForm" class="form-horizontal" method="post" action="">
                            <div class="form-group">
                                <label class="col-sm-3 control-label">WOTC Status:</label>
                                <div class="col-sm-9">
                                    <p class="form-control-static"><?= $eligibility ?></p>
                                </div>
                            </div>
                        </form>
                        <br /><br />
                    </div>
                </div>
            </div>
        </div>
        <?php
    }


	function displayApplicationStep( $application ) {
	// Links job seeker to WMS WOTC screening by submitting a web form. Must ensure that the information necessary has been entered
	// by the user (validated SSN, name & address).
	//
	// Parameters received: application - Application object, current application being evaluated
	// Parameters returned: None
		// There are multiple sections for handling the processing of the WMS WOTC.
		// 1. Collect and verify required information.
		// 2. Transfer job seeker to WMS WOTC using iframe.
		// 3. Returned information from WMS WOTC must be processed.
		$this->jobSeeker     = $application->jobSeeker;
		$this->applicationID = $application->id;
		$this->location      = $application->requisition->location;
		$this->client        = $application->jobSeeker->client;

		switch ( $this->wotcStage ) {
			// Transfer the job seeker to WMS.
			case 'transfer' :
				$transferScreen = $_GET['ts'];
			// The transfer process happens in two parts which is determined by the form value of 'ts'.
				switch ( $transferScreen ) {
			// If the transferScreen is 'iframe', we will be displaying the contents of the iframe. This is the WMS redirection script.
					case 'iframe' :						
						$this->WMSTransfer( $application, $client, location );
						break;

					case 'nocode' :
						echo '<br /><br /><p><b>There is a configuration issue for this location. An administrator has been notified 
and the problem will be corrected.</p> <p>Please try again later.</b></p>';
						exit;
						break;

					default :
						?>
						
						<iframe src="apply.php?ow=1&ts=iframe&ws=transfer&<?php echo SID; ?>" style="width: 650px; height: 550px; border-style: solid; border-width: 1px; border-color: #000;"></iframe> 
	
						<?php
						exit;
						break;
						
				}
				
			// Present and process job seeker information necessary for WMS WOTC.
			default :
				if ( !$this->isLoaded ) {
					$this->loadDataFields( $this->jobSeeker, $this->location, $this->client );
				}	
							
				$this->displayDataFields();			
				break;
		}
		
		return;
	}

	
	function evaluateApplicationStep( $application ) {
	// Processes values returned by Selectech Assessment system and detemines if job seeker has successfully passed.
	//
	// Parameters received: application - Application object, current application being evaluated
	// Parameters returned: wotcStatus - integer, status of WMS WOTC (0 - more needs to be done; 1 - completed)

		$wotcStatus = 0;
		if ( isset( $_GET['ws'] ) ) {
			$this->wotcStage = $_GET['ws'];
		}

		switch ( $this->wotcStage ) {
			// Evaluate returned screening status and retrieve WOTC eligibility information.
			case 'return' :
				// If update of WMS WOTC information is 0, then take the applicant back to beginning of process step.
				// Send email of return values from Walton
				$hostName = gethostname();
				$hostIp = gethostbyname( $hostName );
				//New printFormsLink from Walton
//				$this->formsLink = "'" . $_GET['printFormsLink'] . '&hash=' . $_GET['hash'] . '&OQPrint=' . $_GET['OQPrint'] . '&i_PT=' . $_GET['i_PT'] . '&StartDate=' . $_GET['StartDate'] . '&wage_hourly=' . $_GET['wage_hourly'] . '&JobTitle=' . $_GET['JobTitle'] . '&Custom1=' . $_GET['Custom1'] . '&Custom2=' . $_GET['Custom2'] . '&Custom3=' . $_GET['Custom3'] . '&EmpLocation=' . $_GET['EmpLocation'] . '&NHUpdate=' . $_GET['NHUpdate'] . "'";
				$this->formsLink = "'" . $_GET['printFormsLink'] . '&hash=' . $_GET['hash'] . '&OQPrint=' . $_GET['OQPrint'] . '&i_PT=' . $_GET['i_PT'] . '&StartDate=' . $_GET['StartDate'] . '&wage_hourly=' . $_GET['wage_hourly'] . '&JobTitle=' . $_GET['JobTitle'] . '&Custom1=' . $_GET['Custom1'] . '&Custom2=' . $_GET['Custom2'] . '&Custom3=' . $_GET['Custom3'] . "'";

				$newLine = "\r\n";
				$notificationText = 'Return $_GET Variables from Walton' . ' -> ' . $_GET['Eligible'] . $newLine . $newLine;
				$notificationText .= 'Selectech Server = ' . $hostName . ' (' . $hostIp . ')' . $newLine;
				$notificationText .= 'Enterprise = ' . $_SESSION['enterprise']->name . ' (' . $_SESSION['enterprise']->id . ')' . $newLine;
				$notificationText .= 'Client = ' . $_SESSION['client']->name . ' (' . $_SESSION['client']->id . ')' . $newLine . $newLine;
				foreach( $_GET as $name => $value ) {
				   $notificationText .= $name . ': ' .  $value . $newLine;
				}
				
                                require_once( '../classes/Email.inc' );
				$mailItem = new Email();
				$mailItem->subject = 'Return $_GET Variables from Walton - ' . $hostIp;
				$mailItem->to = SYSTEM_NOTIFICATION_EMAIL; 
				$mailItem->mime->setTXTBody( $notificationText );							 
				$mailItem->sendEmail();
                
				$wotcStatus = $this->updateWMSWOTC( $application, $this->formsLink );
				if ( $wotcStatus == 0 ) {
					$this->wotcStage = 'collection';
				}

				break;
				
			// Transferring the applicant to WMS will not require any evaluation.
			case 'transfer' :
				break;
				
			// Evaluate job seeker information necessary for WMS WOTC.
			default :
				if ( $this->evaluateDataFields( $application ) ) {
					$this->wotcStage = 'transfer';
				} else {
					$this->wotcStage = 'collection';
				}
				
				break;
		}
				
		return $wotcStatus;
	}
	

	function loadApplicationProcessStep( $stepID, $application ) {
	// Verifies and loads the process step and any available application information.  
	//
	// Parameters received: stepID - integer, identifier of the process step to load
	// 						application - Application object, current application being evaluated
	// Parameters returned: none
		parent::loadApplicationProcessStep( $stepID, $application );
		
		/**
		 * Change related to Hersha's moving from ADP to Walton for WOTC processing.
		 * ADP screening step was bypassed when it was not working. The process step was not seen
		 * in list of process steps for applications.
		 * After switching requisitions to be using the Walton process step, it was seen with
		 * blank values.
		 * Solution is to check associated process. If it is completed and the process step is
		 * not completed, then do not consider process step to be valid. This will not associate
		 * it with the application.
		 */
		if ( $this->process->isComplete && !$this->isComplete ) {
			$this->isValid = false;
		} else {
			// Load WOTC information associated with applicant, if any.
			$query 	= 'SELECT screening_status, wotc_eligible, report_url FROM client.adp_wotc WHERE application_id=' . $application->id;
			$result = sql_query( $query );
			if ( $row = mysql_fetch_assoc( $result ) ) {			
				$this->reportURL 		= $row['report_url'];
				$this->screeningStatus 	= $row['screening_status'];
				$this->WOTCEligible 	= $row['wotc_eligible'];
			}
		}
		
		return;
	}


	function displayDataFields()  {
	// Displays the data collection screen to collect job seeker information necessary for WMS WOTC.
	// 
	// Parameters received: None
	// Parameters received: None		
	?>
	
	<p style="margin-left: 10px;">
		<b>This company participates in the federal government's Work Opportunity Tax Credit, Welfare to Work, and other 
		federal and state tax credit programs. The information you supply will be used by this company to complete its federal and state tax returns, and in no way will negatively impact any hiring decision. Your responses to the questions will be confidential to the employer's management and federal, state and local agencies.</b></p>
	<p style="margin-left: 10px;"><b>As part of this process, we need to collect your social security number.</b></p>
		<?php
		echo '<div class="attentionText">' . implode( '<br />', $this->errorList ) . '</div>';
		echo '<br/>';
		echo '<form name="applyForm" action="apply.php" method="post">';
		postSessionInfo();

		echo '<input type="hidden" name="wotcScreen" value="collection" />';
		echo '<div align="center">';
		echo '<table border="0px">';
		echo '<tr><td colspan="2" class="customItemText">Required information is indicated by an asterisk ( <span class="required">*</span> ).<br /><br /></td></tr>';
		echo 	'<tr>';
		echo 		'<td align="left"><span class="required">*</span>&nbsp;First Name:</td>';
		echo 		'<td align="left">';
		$this->dataFields['firstName']->displayInputField();
		echo 		'</td>';
		echo 	'</tr>';
		echo 	'<tr>';
		echo 		'<td align="left">&nbsp;Middle Name:</td>';
		echo 		'<td align="left">';
		$this->dataFields['middleName']->displayInputField();
		echo 		'</td>';
		echo 	'</tr>';
		echo 	'<tr>';
		echo 		'<td align="left"><span class="required">*</span>&nbsp;Last Name:</td>';
		echo 		'<td align="left">';
		$this->dataFields['lastName']->displayInputField();
		echo 		'</td>';
		echo 	'</tr>';
		echo 	'<tr>';
		echo 		'<td align="left"><span class="required">*</span>&nbsp;Address 1:</td>';
		echo 		'<td align="left">';
		$this->dataFields['streetAddress']->displayInputField();
		echo 		'</td>';
		echo 	'</tr>';
		echo 	'<tr>';
		echo 		'<td align="left">&nbsp;Address 2:</td>';
		echo 		'<td align="left">';
		$this->dataFields['streetAddressTwo']->displayInputField();
		echo 		'</td>';
		echo 	'</tr>';
		echo 	'<tr>';
		echo 		'<td align="left"><span class="required">*</span>&nbsp;City:</td>';
		echo 		'<td align="left">';
		$this->dataFields['city']->displayInputField();
		echo 		'</td>';
		echo 	'</tr>';
		echo 	'<tr>';
		echo 		'<td align="left"><span class="required">*</span>&nbsp;State:</td>';
		echo 		'<td align="left">';
		$this->dataFields['state']->displayInputField();
		echo 		'</td>';
		echo 	'</tr>';
		echo 	'<tr>';
		echo 		'<td align="left"><span class="required">*</span>&nbsp;Zip code:</td>';
		echo 		'<td align="left">';
		$this->dataFields['zip']->displayInputField();
		echo 		'</td>';
		echo 	'</tr>';		
		echo 	'<tr>';
		echo 		'<td align="left"><span class="required">*</span>&nbsp;SSN:</td>';
		echo 		'<td align="left">';
		$this->dataFields['ssn']->displayInputField();
		echo 		'</td>';
		echo 	'</tr>';
		echo 	'<tr>';
		echo 		'<td align="left"><span class="required">*</span>&nbsp;Confirm SSN:</td>';
		echo 		'<td align="left">';
		$this->dataFields['ssnConfirm']->displayInputField();
		echo 		'</td>';
		echo 	'</tr>';
		echo 	'<tr>';
		echo 		'<td align="left"><span class="required">*</span>&nbsp;Date of Birth:</td>';
		echo 		'<td align="left">';
		$this->dataFields['dob']->displayInputField(0, 90, 0, 0, 0, 0, 1, 0);
		echo 		'</td>';
		echo 	'</tr>';
		echo '</table>';
		echo '</div>';
		echo '<div align="center"><input type="submit" name="submitButton" value="Submit" /></div>';
		echo '<br />';
		echo '</form>';

		return;
	}

	function evaluateDataFields( $application ) {
	// Evaluates the data entered by job seeker on collection screen used to collect job seeker information necessary for WMS WOTC.
	// 
	// Parameters received: application - Application object, application currently being evaluated
	// Parameters received: isValid - boolean, indicator of valid evaluation
		$isValid = true; 				// Evaluation is valid until found to be invalid.
		$this->errorList = array(); 	// Before evaluation, there are no errors.
		// Check to make sure all dataFields are completed correctly.
		if ( !evaluatePageFields( $this->dataFields ) ) {

			$this->dataFields['firstName']->fieldValue        = $this->replace_quotes( $this->dataFields['firstName']->fieldValue );
			$this->dataFields['middleName']->fieldValue       = $this->replace_quotes( $this->dataFields['middleName']->fieldValue );
			$this->dataFields['lastName']->fieldValue         = $this->replace_quotes( $this->dataFields['lastName']->fieldValue );
			$this->dataFields['streetAddress']->fieldValue    = $this->replace_quotes( $this->dataFields['streetAddress']->fieldValue );
			$this->dataFields['streetAddressTwo']->fieldValue = $this->replace_quotes( $this->dataFields['streetAddressTwo']->fieldValue );
			$this->dataFields['city']->fieldValue             = $this->replace_quotes( $this->dataFields['city']->fieldValue );
			$this->dataFields['zip']->fieldValue              = $this->replace_quotes( $this->dataFields['zip']->fieldValue );
			
			$this->errorList[] = 'Not all of the fields have been completed. Those that are invalid are displayed in yellow.';
			$isValid = false;
		} else {

			$this->dataFields['firstName']->fieldValue        = $this->replace_quotes( $this->dataFields['firstName']->fieldValue );
			$this->dataFields['middleName']->fieldValue       = $this->replace_quotes( $this->dataFields['middleName']->fieldValue );
			$this->dataFields['lastName']->fieldValue         = $this->replace_quotes( $this->dataFields['lastName']->fieldValue );
			$this->dataFields['streetAddress']->fieldValue    = $this->replace_quotes( $this->dataFields['streetAddress']->fieldValue );
			$this->dataFields['streetAddressTwo']->fieldValue = $this->replace_quotes( $this->dataFields['streetAddressTwo']->fieldValue );
			$this->dataFields['city']->fieldValue             = $this->replace_quotes( $this->dataFields['city']->fieldValue );
			$this->dataFields['zip']->fieldValue              = $this->replace_quotes( $this->dataFields['zip']->fieldValue );
			// Check entered ssn for validity.
			$enteredSSN = new SSN();			
			$enteredSSN->setEncrypted( $this->dataFields['ssn']->fieldValue );
			// When done testing put this back on!!!
	
			if ( !$enteredSSN->validateSSN() ) {
				$this->errorList[] = 'The entered SSN is not valid.';
				$this->dataFields['ssn']->isValid = false;
				$isValid = false;
			}
			
			// Compare ssn against ssn confirmation for equality.
			if ( $this->dataFields['ssn']->fieldValue != $this->dataFields['ssnConfirm']->fieldValue ) {
				$this->errorList[] = 'The entered SSN does not match the SSN confirmation.';
				$this->dataFields['ssn']->isValid 			= false;
				$this->dataFields['ssnConfirm']->isValid 	= false;
				$isValid = false;
			}

			// Check entered address for validity.
			$enteredAddress = new Address();
			$enteredAddress->streetAddress    = $this->dataFields['streetAddress']->fieldValue;
			$enteredAddress->streetAddressTwo = $this->dataFields['streetAddressTwo']->fieldValue;
			$enteredAddress->city 			  = $this->dataFields['city']->fieldValue;
			$enteredAddress->state			  = $this->dataFields['state']->fieldValue;
			$enteredAddress->zip			  = $this->dataFields['zip']->fieldValue;

			if ( !$enteredAddress->validateAddress() ) {
			// Entered address is not valid.
				$this->errorList[] = 'The entered address is not valid.';
				$isValid = false;
			} 
			// If all of the entered information is valid, update JobSeeker (object) information.
			$jobSeeker = $application->jobSeeker;
			$jobSeeker->ssn    			  		  = $enteredSSN;
			$jobSeeker->firstName                 = htmlspecialchars_decode( $this->dataFields['firstName']->fieldValue, ENT_NOQUOTES);
			$jobSeeker->middleName       		  = htmlspecialchars_decode( $this->dataFields['middleName']->fieldValue, ENT_NOQUOTES);
			$jobSeeker->lastName 			      = htmlspecialchars_decode( $this->dataFields['lastName']->fieldValue, ENT_NOQUOTES);
			$jobSeeker->address->streetAddress    = htmlspecialchars_decode( $this->dataFields['streetAddress']->fieldValue);
			$jobSeeker->address->streetAddressTwo = htmlspecialchars_decode( $this->dataFields['streetAddressTwo']->fieldValue, ENT_NOQUOTES);
			$jobSeeker->address->city    		  = htmlspecialchars_decode( $this->dataFields['city']->fieldValue, ENT_NOQUOTES);
			$jobSeeker->address->state   		  = $this->dataFields['state']->fieldValue;
			$jobSeeker->address->zip			  = htmlspecialchars_decode( $this->dataFields['zip']->fieldValue, ENT_NOQUOTES);
			$jobSeeker->dateOfBirth               = $this->dataFields['dob']->fieldValue;	
			$jobSeeker->updateUser( $jobSeeker->password );
		}
		
		return $isValid;
	}

	function loadDataFields( $application, $location, $client ) {
	// Loads the data fields used to collect job seeker information necessary for WMS WOTC. Assigns values to the fields, if available.
	// 
	// Parameters received: application, Application object, application currently being evaluated
	// Parameters received: None
		
		$fieldList = array();
		$fieldList['ssn'] = new WebFormField( 'ssn', 'password', array(), true, array(), 'wizardTextInput', 'missingWizardTextInput' );
		$fieldList['ssn']->setFieldValue( $application->ssn->getDecrypted() );
		
		$fieldList['ssnConfirm'] = new WebFormField( 'ssnConfirm', 'password', array(), true, array(), 'wizardTextInput', 'missingWizardTextInput' );
		$fieldList['ssnConfirm']->setFieldValue( $application->ssn->getDecrypted() );
		
		$fieldList['dob'] = new WebFormField( 'dob', 'date', array(), true, array(), 'wizardDateInput', 'missingWizardDateInput' );
		if ( !empty( $application->dateOfBirth ) ) {
			$fieldList['dob']->setFieldValue( $application->dateOfBirth );
		} else {	
			$fieldList['dob']->setFieldValue( '0000-00-00' );
		}	
		
		$fieldList['firstName'] = new WebFormField( 'firstName', 'text', array(), true, array(), 'wizardTextInput', 'missingWizardTextInput' );
		$fieldList['firstName']->setFieldValue( $application->firstName );

		$fieldList['middleName'] = new WebFormField( 'middleName', 'text', array(), false, array(), 'wizardTextInput', 'missingWizardTextInput' );
		$fieldList['middleName']->setFieldValue( $application->middleName );
		
		$fieldList['lastName'] = new WebFormField( 'lastName', 'text', array(), true, array(), 'wizardTextInput', 'missingWizardTextInput' );
		$fieldList['lastName']->setFieldValue( $application->lastName );

		$fieldList['streetAddress'] = new WebFormField( 'streetAddress', 'text', array(), true, array(), 'wizardTextInput', 'missingWizardTextInput' );
		$fieldList['streetAddress']->setFieldValue( $application->address->streetAddress );

		$fieldList['streetAddressTwo'] = new WebFormField( 'streetAddressTwo', 'text', array(), false, array(), 'wizardTextInput', 'missingWizardTextInput' );
		
		$fieldList['city'] = new WebFormField( 'city', 'text', array(), true, array(), 'wizardTextInput', 'missingWizardTextInput' );
		$fieldList['city']->setFieldValue( $application->address->city );
		
		$fieldList['state'] = new WebFormField( 'state', 'select', array(), true, array(), 'wizardTextInput', 'missingWizardTextInput' );
		$fieldList['state']->responseOptions = loadStateResponses();
		$fieldList['state']->setFieldValue( $application->address->state );

		$fieldList['zip'] = new WebFormField( 'zip', 'text', array(), true, array(), 'wizardTextInput', 'missingWizardTextInput' );
		$fieldList['zip']->setFieldValue( $application->address->zip );

		$this->dataFields 	= $fieldList;
		$this->isLoaded 	= true;
		
		return;
	}

	function WMSTransfer( $application, $client, $location ) {
	
	    // Transfers the job seeker to the WMS WOTC system.
		// get client ID from CCV
		//$this->WMSClientId 	= getClientConfigValue( 'WMS_CLIENT_ID' );
		
		// Get the location_id to pass to WMS WOTC system.
		
		$query 	= 'SELECT adp_location_id, wms_client_code FROM client.adp_locations WHERE location_id=' . $application->requisition->location->id;
		$result = sql_query( $query );
		
		// If there is no ADP location code associated with the requisition location, then this process step cannot be used.
		if ( !( $row = mysql_fetch_assoc( $result ) ) ) {
			$this->isValid = false;
			?>
			
			<iframe src="apply.php?ow=1&ts=nocode&ws=transfer&<?php echo SID; ?>" style="width: 650px; height: 550px; border-style: solid; border-width: 1px; border-color: #000;"></iframe>

			<?php			
			$newLine = "\r\n";	
                        $errorResponse .= ' No WMS location id for: ' . $newLine . $newLine;
		        $errorResponse .= ' Client Name = ' . $application->jobSeeker->client->name . $newLine;
		        $errorResponse .= ' Location ID = ' . $application->requisition->location->id . $newLine;
			$errorResponse .= ' Location Name = ' . $application->requisition->location->name . $newLine;
  		        $errorResponse .= ' Applicant Name = ' . $application->jobSeeker->firstName . " " .  $application->jobSeeker->lastName ;
                        require_once( '../classes/Email.inc' );
			
                        $mailItem = new Email();
			$mailItem->subject = 'WMS WOTC Location Information not available';
			$mailItem->to = SYSTEM_NOTIFICATION_EMAIL;
			$mailItem->mime->setTXTBody( $errorResponse );
			$mailItem->sendEmail(); 			
		} else {
			$this->WMSLocID = $row['adp_location_id'];
			if ( !empty( $row['wms_client_code'] ) ) {
				$this->WMSClientId = $row['wms_client_code'];
			} else {
				$this->WMSClientId 	= getClientConfigValue( 'WMS_CLIENT_ID' );	
			}		
			
		}
		
                $DOB = date("m/d/Y" , strtotime( $this->dataFields['dob']->fieldValue ) ); //format date for WMS online form
	 	//$returnURL = 'http://' . $_SERVER['HTTP_HOST'] . '/jobseeker/apply.php?ws=return&' . SID;
		$returnURL = 'https://' . $_SERVER['HTTP_HOST'] . '/frame_work/return.php?ws=return&rSessionID=' . SID;
		$ERCSESSID = SID;
		$custID = $this->clean_up( $this->WMSClientId );
                
		$this->transferURL = 'https://questionnaire.waltonmgt.com/?CustID=' . $custID;                 
		//$this->transferURL = 'https://questionnaire.waltonmanagement.com/?CustID=' . $custID; 
                // 4/12/10 $this->transferURL = 'https://www.waltonmanagement.com/questionnaire/Service/gateway.jsp'; 
                //$this->transferURL = 'https://www.waltonmanagement.com/netcentives/questionnaire/gateway.jsp'; 
               //$this->transferURL = 'https://www.waltonmanagement.com/questionnaire/Service/AppInfo_Request.jsp'; 
		?>
		
		<p align="center"><br /><br /><br /><br /><br /><br /><br /><br /><br /><b>Please wait while we redirect you to Walton WOTC</b></p>

		<form name="wmsTransferForm" method="post" action="<?php echo $this->transferURL; ?>">		
		<input type="hidden" name="transferURL" value="<?php echo $this->transferURL; ?>" />
		<input type="hidden" name="rURL" value="<?php echo $returnURL; ?>" />
		<input type="hidden" name="rSessionID" value="<?php echo $ERCSESSID; ?>" />
		<input type="hidden" name="DOB" value="<?php echo $DOB; ?>" />
		<input type="hidden" name="SSN" value="<?php echo $this->clean_up( $this->dataFields['ssn']->fieldValue ); ?>" />
		<input type="hidden" name="AppID" value="<?php echo $this->clean_up( $this->applicationID ); ?>" />
		<input type="hidden" name="CustID" value="<?php echo $this->clean_up( $this->WMSClientId ); ?>" />
		<input type="hidden" name="LocID" value="<?php echo $this->clean_up( $this->WMSLocID ); ?>" />
		<input type="hidden" name="FName" value="<?php echo $this->clean_up( $this->dataFields['firstName']->fieldValue ); ?>" />
		<input type="hidden" name="MName" value="<?php echo $this->clean_up( $this->dataFields['middleName']->fieldValue );?>"/>
		<input type="hidden" name="LName" value="<?php echo $this->clean_up( $this->dataFields['lastName']->fieldValue ); ?>" />
		<input type="hidden" name="AddrStreet1" value="<?php echo $this->clean_up( $this->dataFields['streetAddress']->fieldValue ); ?>" />
		<input type="hidden" name="AddrStreet2" value="<?php echo $this->clean_up( $this->dataFields['streetAddressTwo']->fieldValue ); ?>" />
		<input type="hidden" name="AddrCity" value="<?php echo $this->clean_up( $this->dataFields['city']->fieldValue ); ?>" />
		<input type="hidden" name="AddrState" value="<?php echo $this->clean_up( $this->dataFields['state']->fieldValue ); ?>" />
		<input type="hidden" name="AddrZip" value="<?php echo $this->clean_up( $this->dataFields['zip']->fieldValue ); ?>" />		
		<input type="hidden" name="EmpLocation" value="<?php echo $this->clean_up( $this->WMSLocID ); ?>" />		
		</form>
      
	        <?php
                $email_ssn = $this->dataFields['ssn']->fieldValue;
                $email_ssn = substr_replace($email_ssn, "XXXXX", 0, 5);

		$newLine = "\r\n";	
		$errorResponse .=  $newLine . $newLine;		
		$errorResponse .= ' Enterprise Name:'   . $_SESSION['enterprise']->name . $newLine;
		$errorResponse .= ' Client Name:'   . $application->jobSeeker->client->name . $newLine;
	        $errorResponse .= ' Location ID = ' . $application->requisition->location->id . $newLine;
		$errorResponse .= ' Location Name = ' . $application->requisition->location->name . $newLine;
		$errorResponse .=  $newLine . $newLine;
		
		$errorResponse .= ' transferURL: '  . $this->transferURL . $newLine;
		$errorResponse .= ' rURL: '         . $returnURL . $newLine;
		$errorResponse .= ' rSessionID: '   . $ERCSESSID . $newLine;
		$errorResponse .= ' DOB: '          . $DOB . $newLine;
		$errorResponse .= ' SSN: '          . $email_ssn . $newLine;
		$errorResponse .= ' AppID: '        . $this->applicationID . $newLine;
		$errorResponse .= ' CustID: '       . $this->WMSClientId . $newLine;
		$errorResponse .= ' LocID: '        . $this->WMSLocID . $newLine;
		$errorResponse .= ' First Name: '   . $this->clean_up( $this->dataFields['firstName']->fieldValue ) . $newLine;
		$errorResponse .= ' Middle Name: '  . $this->clean_up( $this->dataFields['middleName']->fieldValue ) . $newLine;
		$errorResponse .= ' Last Name: '    . $this->clean_up( $this->dataFields['lastName']->fieldValue ) . $newLine;
		$errorResponse .= ' Address1: '     . $this->clean_up( $this->dataFields['streetAddress']->fieldValue ) . $newLine;
		$errorResponse .= ' Address2: '     . $this->clean_up( $this->dataFields['streetAddressTwo']->fieldValue ) . $newLine;
		$errorResponse .= ' City: '         . $this->clean_up( $this->dataFields['city']->fieldValue ) . $newLine;
		$errorResponse .= ' State: '        . $this->clean_up( $this->dataFields['state']->fieldValue ) . $newLine;
		$errorResponse .= ' Zip code: '     . $this->clean_up( $this->dataFields['zip']->fieldValue ) . $newLine;
		$errorResponse .= ' EmpLocation: '  . $this->WMSLocID;
		$errorResponse .=  $newLine . $newLine;
		$errorResponse .=  $_SERVER['HTTP_USER_AGENT'] . $newLine;

		require_once( '../classes/Email.inc' );
                $mailItem = new Email();
		$mailItem->subject = 'WMS WOTC Post Information';
		$mailItem->to = SYSTEM_NOTIFICATION_EMAIL;
		$mailItem->mime->setTXTBody( $errorResponse );
		$mailItem->sendEmail(); 

        ?>
		<script type="text/javascript">
			document.wmsTransferForm.submit();
		</script>
	<?php			
	return; 
   }	
   
 	function updateWMSWOTC( $application, $formsLink ) {
	// Updates information related to the WMS WOTC process step. Evaluates status returned from WMS, then retrieves the eligibility 
	// status from WMS using query string post.
	//
	// Parameters received: application - Application object, application currently being evaluated
	// Parameters returned: $wotcStatus - integer, status of process step
		$wotcStatus	= 0; // integer, Status of process step.
		$this->screeningStatus = intval( $_GET['Eligible'] );	// Status returned from WMS.
		
		// Update wotc status. need to create a new table for WMS WOTC - (wms.wotc)
		$query 	= 'INSERT INTO client.adp_wotc 
				   SET application_id='	. $application->id . ', 
				   	   wotc_eligible=' 	. $this->screeningStatus . ',	
					   report_url='	. $formsLink . '			   	  
				   ON DUPLICATE KEY UPDATE 
				   wotc_eligible=' . $this->screeningStatus . ', 	
				   report_url=' . $formsLink;	

		if ( sql_query( $query ) ) {
			$wotcStatus = 1;
		}
		return $wotcStatus;
	}
	
	function clean_up( $str ) {   	    

		$str = str_replace ( '"', '\'', $str );
		$str = str_replace ( '\'', ' ', $str );		
		$str = preg_replace('/(^"+|"+$)/','',$str); 

		$str = htmlentities( $str );
		$str = htmlspecialchars( $str);

		$str = preg_replace("/#/", "", $str);		
		$str = trim( $str );
		return( $str );
	}

	function replace_quotes( $str ) {   	    
		
		$str = str_replace ( '"', '\'', $str );
		$str = str_replace ( '\'', ' ', $str );		
		$str = preg_replace('/(^"+|"+$)/','',$str); 
		$str = trim( $str );
		
		return( $str );
	}
}

