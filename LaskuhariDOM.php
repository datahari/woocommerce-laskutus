<?php
/**
 * Class for creating DOM elements
 */
class LaskuhariDOM
{
    public static function create_select_box( $name, $options, $current = '' ) {
        $html = '<select name="'.esc_attr( $name ).'">';
        foreach( $options as $value => $text ) {
            $html .= '<option value="'.esc_attr( $value ).'"';
            if( $current === $value ) {
                $html .= " selected";
            }
            $html .= '>'.esc_html( $text ).'</option>';
        }
        $html .= '</select>';
        return $html;
    }
}
