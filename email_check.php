<?php
/**
 * Class for handling email input in HTML forms.
 *
 * An email field does not have any field-specific settings.
 *
 * Simple example building and displaying a phone field:
 * <code>
 * $email = new WebForm_Field_Email( 'email', 'Email', '', true );
 * $email->display();
 * </code>
 *
 * @package WebForm\Field
 */
class WebForm_Field_Email extends WebForm_Field_Text {

    /**
     * If field is required, verify the field is not blank.
     *
     * @return boolean
     */
    public function evaluate() {
        $this->passedEvaluation = true;
        $this->error = '';

        $newValue = '';
        if( isset($_POST[$this->name]) ) {
            $newValue = filter_var( $_POST[$this->name], FILTER_SANITIZE_EMAIL );
        }

        $this->setValue( $newValue );

        if( $newValue != '' && !self::isValidEmail( $newValue ) ) {
            $this->error = getStaticTextItem( 'FIELDS_SYSTEM', 'basic_error_message_email', getSessionLanguageId() );
        }

        if( ( $this->isRequired && $newValue == '' ) || !empty( $this->error ) ) {
            $this->passedEvaluation = false;
        }

        return $this->passedEvaluation;
    }

    /**
     * Validates and email address.
     *
     * @param string $email
     * @return boolean
     */
    public static function isValidEmail( $email ) {
        return preg_match( '/^[a-zA-Z0-9_\.\-\+\'\]+@[a-zA-Z0-9\-]+\.[a-zA-Z0-9\-\.]+$/', trim( $email ) );
    }

}
?>
