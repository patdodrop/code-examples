<?php
define("RelativePath", ".");
if ( $_GET['export'] != 'Excel' ) {
        include(RelativePath . "/Common.php");
}	
require_once( '../include/includes.php' );
require_once("report-class.php");
require_once( '../classes/HTML2PDF.inc' );
require_once( '../include/mysql_functions.php' );
session_start ();

class reqReportGenerator extends reportGenerator{
    
    public $html_source;

    function assignReportVars()
    {
        $this->border               = ( empty( $this->border ) ) ? "0": $this->border;
        $this->cellpad              = ( empty( $this->cellpad ) ) ? "0": $this->cellpad;
        $this->cellspace            = ( empty( $this->cellspace ) )? "0": $this->cellspace;
        $this->width                = ( empty( $this->width ) ) ? "100%": $this->width;
        $this->header_color         = ( empty( $this->header_color ) ) ? "#FFFFFF": $this->header_color;
        $this->header_textcolor     = ( empty( $this->header_textcolor ) ) ? "#000000": $this->header_textcolor;		
        $this->header_alignment     = ( empty( $this->header_alignment ) )? "left": $this->header_alignment;
        $this->table_class          = ( empty( $this->table_class ) ) ? "Grid": $this->table_class;
        $this->header_class         = ( empty( $this->header_class ) ) ? "Caption": $this->header_class;
        $this->body_color           = ( empty( $this->body_color ) ) ? "#FFFFFF": $this->body_color;
        $this->body_textcolor       = ( empty( $this->body_textcolor ) ) ? "#000000": $this->body_textcolor;
        $this->body_alignment       = ( empty( $this->body_alignment ) ) ? "left": $this->body_alignment;
        $this->surrounded           = ( empty( $this->surrounded ) ) ? false:true;
        $this->modified_width       = ( $this->surrounded==true ) ? "100%": $this->width;
        $this->header_count         = count( $this->headers );	
        $this->main_header_count    = count( $this->main_headers );	
        $this->data_count           = count( $this->data );	
        $this->no_records           = 'No Records';	
    }

    function createReportCSS()
    {
        $css =" <head>
            <style type=text/css>
                <!--
                    td { font-family: Arial; font-size: 6px; text-align: justify; }	 
                    th { font-family: Arial; font-size: 6px; text-align: justify; }
                    p { font-size 11pt; }
                    .row { 
                            font-size: 6px;
                            font-weight: normal;
                            text-align: justify; 
                            vertical-align: top;
                            border-left: 1px solid #eaeaea;
                            background-color: #f7f7f7;
                            color: #000000;
                    }										
                    -->
                </style>
           </head>";
         return $css;
    }
    
    function downloadReportPDF()
    {
        //print_r($this->html_source);exit;
        ob_start();
        // Generate filename of the PDF to be attached
        $fileName = date('Ymd');
        $pdfFile = tempnam( '/tmp', $fileName );

        // Initialize the HTML2PDF object
        $html2pdf = new HTML2PDF();
        $html2pdf->pageLayout   = 'L'; // Layout: L = Landscape, P = Portrait
        $html2pdf->referenceURL = 'https://' . $_SESSION['enterprise']->enterpriseURL. '/';
        $html2pdf->htmlSource = $this->html_source;			
        $html2pdf->convertHTML();
        $html2pdf->output( $pdfFile . ".pdf", 'D' );	 
    }
    
    function createLocationPDF()
    {
        $this->html_source = $this->createReportCSS();
        $this->html_source .= $this->createHTML('PDF');
        $this->downloadReportPDF();                                       
    }
    
    function createHTML($type) {
        
        $html_source = '<table cellspacing=0 cellpadding=0 border=0 width=100%>';
                
        if($type == 'HTML')
        {
            $html_source .=  "<tr><td align=left valign=bottom bgcolor=#E6EFF7>Click on the icon to download data
                  <a href=" . $PHP_SELF . "?export=Excel&" . $this->query_string . "><img src='../images/excel1.gif' NAME='but1' border='0'></a>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
            $html_source .= "<a href=" . $PHP_SELF . "?export=PDF&" . $this->query_string . "><img src='../images/pdf_old.gif' border='0'></a>&nbsp;&nbsp;&nbsp;&nbsp;";
            $html_source .= "</td> </tr>";	
       
            $html_source .=  "<tr><td colspan=9>
                <table class=Header cellspacing=0 cellpadding=0 border=0>
                       <tr>
                         <td class=HeaderLeft><img src=Styles/Compact/Images/Spacer.gif border=0></td> 
                            <th>" . $this->report_title . "</th> 
                         <td colspan=" . $this->header_count . "><img src=Styles/Compact/Images/Spacer.gif border=0></td>
                       </tr>
                  </table>
                </td></tr>";
        }
        
        if ( empty( $this->locations_data ) ) {                
            $html_source .= "<table width=100%><tr class=Row>";
            $html_source .= "<td>&nbsp;<b>No Records</b></td>";
            $html_source .= "</tr></table>";
            if($type == 'HTML'){
                echo $html_source;
            } else if($type == 'PDF'){
                return $html_source;
            }
            exit;
        }
        
        if ( !empty( $this->top_header ) ) {
            $html_source .= "<tr class=Row><td align=left><b>".$this->top_header."</b>&nbsp;</td></tr>";
        }
        
        if ( !empty( $this->main_headers ) ) {	
            
            foreach ( $this->main_headers as $field => $value ) {
                $bgcolor = '#E6EFF7';
                if($type == 'PDF'){
                    $bgcolor = '#ADD8E6';
                }
               $html_source .= "<tr bgcolor=".$bgcolor."><td align=left style='padding-left:2px;'><b>".$field." ".$value."</b>&nbsp;</td></tr>";
            }
            
        }
        
        $html_source .=  "<table cellspacing='0' width='100%' cellpadding='0' class='sortable' id='10'><tr class=Caption>";
        
        //Header array
        for ($i = 0; $i< $this->header_count; $i++) {
                //print column headers
                if($type == 'PDF')
                {
                    $html_source .=  "<th class=Row style='width=8%;font-weight:bold;background-color: #E6EFF7;border:0px'>&nbsp;".$this->headers[$i]."</th>";
                    //$html_source .=  "<th>&nbsp;".$this->headers[$i]."</th>";
                }
                else{
                    $html_source .=  "<th>&nbsp;".$this->headers[$i]."</th>";
                }
  
        }
        $html_source .=  "</tr>";
        //Now fill the table with data
        foreach ( $this->locations_data as $row ) {
            $html_source .=  "<tr class=Row>";								
            foreach ($row as $field => $value) {			
                //echo $field.'<br>';
                $this->data_count = $value;
                if($type == 'PDF'){
                    switch ($field) {
                        case 'first_name':
                          $value = wordwrap($value,15, "<br/>");  
                          break;
                        case 'last_name':
                          $value = wordwrap($value,15, "<br/>");  
                          break;
                        case 'race':
                          $value = wordwrap($value,15, "<br/>");
                          break;
                        case 'gender':
                          $value = wordwrap($value,10, "<br/>");  
                          break;
                        case 'veteran':
                          $value = wordwrap($value,12, "<br/>");
                          break;
                    } 
                }   
                $html_source .=  '<td style="padding:0px;width:auto">&nbsp;'. $value ."</td>";
            }
            $html_source .=  "</tr>";
        }
        
        if ( !empty( $this->counts ) ) {			
            $html_source .=  "<tr class=Row><td><b>Totals</b></td>";											
            foreach ( $this->counts as $field => $value ) {	
               $html_source .=  "<td align=left><b>" . $value . "</b>&nbsp;</td>";
            }
            $html_source .=  "</tr>";
        }
        
        $html_source .= "</table>";
        $html_source .= "<table width='100%' cellspacing='0' cellpadding='0'>";
        $html_source .= "<tr class=Row><td colspan=".$this->header_count."><b>Total Records:"." ".$this->row_count."</b></td></tr>";
        
        if($type == 'HTML'){
            $html_source .= "<tr class=Row><td colspan=".$this->header_count.">"." "."<b>".$this->footer . "</b></td></tr></table>";                     
            echo $html_source;
        }else if($type == 'PDF'){
            $html_source .= "<tr class=Row><td colspan=".$this->header_count.">".$this->report_title ." "."<b>".$this->footer . "</b></td></tr></table>";                     
            
            return $html_source;
        }
    }
    
    function createExcel() {
        
        $run_date =  date("F j, Y, g:i a");
	$filename = $this->report_title . '_' .  $run_date . '.xls'; 

        header( 'Pragma: public' );
        header( 'Expires: 0' );
        header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
        header( 'Cache-Control: public' );
        header("Content-Disposition: filename=" . $filename);
        
        if (strpos($_SERVER["HTTP_USER_AGENT"],"MSIE 5.5;") || strpos($_SERVER["HTTP_USER_AGENT"],"MSIE 5.0;")) {
            header("Content-Type: application/csv");
        } else {
           header("Content-Type: application/vnd.ms-excel"); 
        }	
	
        echo "\n" . $this->report_title . " - Run on " . $run_date ."\n";
        
        if ( !empty( $this->date_range ) ) {
                echo 'Date Range:' . ' ' . $this->start_date . ' - ' . $this->end_date . "\n";
        }	
        if ( !empty( $this->top_header ) ) {
                        print $this->top_header . "\n";
        }		

        if ( !empty( $this->main_headers ) ) {
                foreach ( $this->main_headers as $field => $value ) {
                        print $field ." " . $value . "\n";
                }	
        }
       
        $header = implode( "\t",$this->headers ) ."\n";
        
        print $header;				
        foreach ($this->locations_data as $row) {
            $value = implode( "\t",$row ) ."\n";		
            $oldValues = array( 'â€¢',    'â€“',    'Ã©',     'â€œ',    'â€?',    'â€™',    'Â·',    'ï€®',    'ï‚§',     'ï?®', '&;amp;', '&amp;', '', '&lt;br&gt;' );
            $newValues = array( '&#149;', '&#150;', '&#233;', '&#147;', '&#148;', '&#180;', '&#149;', '&#149;', '&#149;', '&#149;', '&', '&', ' ', '<br />' );
            $value = str_replace( $oldValues, $newValues, $value );
            echo $value;
        }	
		
        if ( !empty( $this->counts ) ) {			
            echo "Totals:". "\t";											
            foreach ( $this->counts as $field => $value ) {	
               echo $value . "\t";
            }
        } else {
            echo "Total Records:" .  " " . $this->row_count . "\n";			
        }	
        echo "\n" . $this->report_title . " - Run on " . $run_date ."\n";	
    }
    
    function displayReport() 
    {
        $this->assignReportVars();
        $this->date_range           = $this->convertdate($this->start_date, 2) . $this->convertdate($this->end_date, 2);
        $this->start_date           = $this->convertdate($this->start_date, 2);
        $this->end_date             = $this->convertdate($this->end_date, 2);

        if ( empty( $this->locations_data ) ) {
                $this->locations_data[] = array( $this->no_records );
                $this->data_count = count( $this->locations_data );	
        }	
        $this->data_count = count( $this->locations_data );
        if ( empty( $this->display_type ) ) {
                $this->display_type = 'HTML';
        }

        switch ( $this->display_type ) {
            case 'HTML' :
                $this->createHTML($type='HTML');
                break;
            case 'Excel' :
                $this->createExcel();
                break;

            case 'PDF' :
                $this->createLocationPDF();
                break;
         } //END SWITCH
   }
}

$run_date = date("F j, Y, g:i a"); 
$report = new reqReportGenerator();
$report->class           = "sofT";
$report->header_color    = "#eaeaea";
$report->footer          = "Run on - " . $run_date;
$report->start_date      = $_GET['startDate'];
$report->end_date        = $_GET['endDate'];
$report->report_title    = $_GET['jrs_report_name'];
$report->display_type    = $_GET['export'];
$report->query_string    = $_SERVER['QUERY_STRING']; 
$report->date_range      = $report->convertdate($report->start_date, 2) . " - " . $report->convertdate($report->end_date, 2);
$report->requisition_id  = $_GET['requisitionID'];
check_valid_user();
$adminUser = $_SESSION['adminUser'];
$adminUser->client->databaseConnect();

$report->headers = array( 'Date Applied','First Name', 'Last Name', 'Zip Code', 'Race', 'Gender', 'Referral Source', 'Veteran Status', 'Veteran Type', 'Disability Status', 'Disposition', 'Hire Date' );
$sql = "SELECT a.application_date, js.first_name, js.last_name, js.zip,
        l.location_name, r.requisition_id, r.name, r.open_date, r.close_date, sr.status_name, a.application_id, a.referral_source, r.position_id, p.position_name, js.user_id, ae.veteran, ae.veteran_type,
        CASE
            WHEN ae.disability=1 THEN 'Not Disabled'
            WHEN ae.disability=2 OR ae.disability_identified_admin=1 THEN 'Disabled'
            WHEN ae.disability=3 OR ae.disability_identified_admin=1 THEN 'Undisclosed'
            ELSE ''
        END as disability,
        (SELECT rc.name FROM client.reference_codes rc WHERE rc.reference_code='gender' AND rc.value=ae.gender) AS gender,
        (SELECT rc.name FROM client.reference_codes rc WHERE rc.reference_code='race' AND rc.value=ae.race) AS race,
        (SELECT rc.name FROM client.reference_codes rc WHERE rc.reference_code='referral' AND rc.value=a.referral_source) AS referral,				
        (SELECT DISTINCT nhd.value FROM client.newhire_data nhd WHERE application_id = a.application_id AND newhire_reference_code = 'HireDate') AS hDate, 		
        (" . getMultilingualQuery( 'Requisition', 'name', 'r.requisition_id', 1 ) . ") AS requisition_name, 
        (" . getMultilingualQuery( 'Location', 'name', 'r.location_id', 1 ) . ") AS location_name, 
        (" . getMultilingualQuery( 'Position', 'name', 'p.position_id', 1 ) . ") AS pos_name 
        FROM client.applications a
        LEFT JOIN client.requisitions r ON a.requisition_id=r.requisition_id
        LEFT JOIN client.positions p ON  p.position_id=r.position_id
        LEFT JOIN client.locations l ON r.location_id=l.location_id
        LEFT JOIN client.job_seekers js ON a.job_seeker_id=js.user_id
        LEFT JOIN client.application_eeo ae ON a.application_id=ae.application_id AND ae.process_step_id = (SELECT sub_aps.process_step_id
            FROM client.application_eeo sub_ae
            JOIN client.application_process_steps sub_aps ON sub_aps.application_id=sub_ae.application_id
                AND sub_aps.process_step_id=sub_ae.process_step_id
            WHERE sub_ae.application_id=ae.application_id
              AND sub_aps.is_complete
            ORDER BY sub_aps.end_time DESC
            LIMIT 1)
        LEFT JOIN client.status_reference sr ON sr.status_type='disposition' AND a.disposition=sr.status_value
        WHERE a.requisition_id=" . $report->requisition_id . " AND NOT a.job_seeker_withdrawal AND sr.code_reference <> 'candidate_withdraw'
        ";  


$result = sql_query( $sql );	
$report->num_rows = mysql_num_rows($result);	
$report->row_count = mysql_num_rows($result);
        
if ( !empty( $result ) ) {

    $i = 0;
    while( $row = mysql_fetch_assoc( $result ) ) {
            extract( $row );
            
            $race = $report->getRace($race);
           
            $referralSource = getStaticTextItem( 'REFERRAL_COLLECTION', 'RO_REFERRAL_' . $referral_source, 1 );
            $report->main_headers = array( 'Requisition:'=>$requisition_id . ' - ' . $requisition_name . ' at ' . $location_name, 'Position:'=>$pos_name );
            $report->requisition_name = $requisition_name;
            $report->requisition_id = $requisition_id;
            $report->position_name = $pos_name;

            $report->locations_data[] = array( 'application_date'=>date("m/d/Y", strtotime( $application_date ) ), 'first_name'=>$first_name, 'last_name'=>$last_name, 'zip'=>$zip, 'race'=>$race, 'gender'=>$gender, 'referral_source'=>$referralSource);	         

            $veteran_name = $report->getVeteranName($veteran);
            $veteran_type_name = $report->getVeteranTypeName($veteran_type);

            $report->locations_data[$i]['veteran'] =  $veteran_name;
            $report->locations_data[$i]['veteran_type'] =  $veteran_type_name;
            $report->locations_data[$i]['disability'] =  $disability;


            $report->locations_data[$i]['disposition'] =  $status_name;
            
            if ( empty( $hDate ) ) {
                $hireDate = $report->getHireDate($hDate,$application_id);
            }
            else{
                $hireDate =  date( "m/d/Y", strtotime( $hDate ) );
            }
            
            $report->locations_data[$i]['hire_date'] =  $hireDate;
            $i++;
     }
}       
$report->displayReport();
?>
