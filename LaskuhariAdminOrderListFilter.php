<?php
/**
 * Extension that adds capability to filter admin order list by order invoicing status
 */
class LaskuhariAdminOrderListFilter extends LaskuhariExtension
{
    /**
     * Actions to be added by this extension
     *
     * @var array
     */
    protected array $actions = [
        [ 'pre_get_posts', "change_order_meta_query_based_on_selected_invoicing_status" ],
        [ 'restrict_manage_posts', 'display_select_box_for_filtering_orders_by_invoicing_status' ]
    ];

    /**
     * List of all selectable invoicing status filters and
     * how they are filtered by the status query
     *
     * @var array
     */
    protected array $status_queries = [
        "laskutettu" => [
            [
                'key'     => '_laskuhari_sent',
                'compare' => '=',
                'value'   => "yes",
            ]
        ],
        "ei_laskutettu" => [
            'relation' => 'and',
            [
                'relation' => 'or',
                [
                    'key'     => '_laskuhari_invoice_number',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key'     => '_laskuhari_invoice_number',
                    'compare' => '=',
                    'value'   => '0'
                ],
                [
                    'key'     => '_laskuhari_invoice_number',
                    'compare' => '=',
                    'value'   => ''
                ]
            ],
            [
                [
                    'key'     => '_payment_method',
                    'compare' => '=',
                    'value'   => 'laskuhari'
                ]
            ]
        ],
        "ei_laskutettu_kaikki" => [
            'relation' => 'and',
            [
                'relation' => 'or',
                [
                    'key'     => '_laskuhari_invoice_number',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key'     => '_laskuhari_invoice_number',
                    'compare' => '=',
                    'value'   => '0'
                ],
                [
                    'key'     => '_laskuhari_invoice_number',
                    'compare' => '=',
                    'value'   => ''
                ]
            ],
            [
                'relation' => 'or',
                [
                    'key'     => '_payment_method',
                    'compare' => '=',
                    'value'   => 'laskuhari'
                ],
                [
                    'key'     => '_payment_method',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key'     => '_payment_method',
                    'compare' => '=',
                    'value'   => ''
                ]
            ]
        ],
        "lasku_luotu" => [
            'relation' => 'and',
            [
                'key'     => '_laskuhari_invoice_number',
                'compare' => '>',
                'value'   => "0",
            ],
            [
                'key'     => '_laskuhari_sent',
                'compare' => '!=',
                'value'   => "yes",
            ]
        ],
        "maksettu" => [
            'relation' => 'and',
            [
                'key'     => '_laskuhari_invoice_number',
                'compare' => '>',
                'value'   => "0",
            ],
            [
                'key'     => '_laskuhari_payment_status',
                'compare' => '=',
                'value'   => "1",
            ]
        ],
        "ei_maksettu" => [
            'relation' => 'and',
            [
                'key'     => '_laskuhari_invoice_number',
                'compare' => '>',
                'value'   => "0",
            ],
            [
                'key'     => '_laskuhari_payment_status',
                'compare' => '!=',
                'value'   => "1",
            ]
        ]
    ];

    function display_select_box_for_filtering_orders_by_invoicing_status() {
        global $pagenow, $post_type;

        if( 'shop_order' === $post_type && 'edit.php' === $pagenow ) {
            $current = isset( $_GET['filter_laskuhari_status'] ) ? $_GET['filter_laskuhari_status'] : '';

            $options = [
                ""                     => 'Laskuhari: ' . __( 'Kaikki', 'laskuhari' ),
                "ei_laskutettu"        => 'Laskuhari: ' . __( 'Ei laskutettu', 'laskuhari' ),
                "ei_laskutettu_kaikki" => 'Laskuhari: ' . __( 'Ei laskutettu (Kaikki)', 'laskuhari' ),
                "lasku_luotu"          => 'Laskuhari: ' . __( 'Lasku luotu', 'laskuhari' ),
                "laskutettu"           => 'Laskuhari: ' . __( 'Laskutettu', 'laskuhari' ),
                "maksettu"             => 'Laskuhari: ' . __( 'Maksettu', 'laskuhari' ),
                "ei_maksettu"          => 'Laskuhari: ' . __( 'Ei maksettu', 'laskuhari' )
            ];

            echo LaskuhariDOM::create_select_box( "filter_laskuhari_status", $options, $current );
        }
    }

    function change_order_meta_query_based_on_selected_invoicing_status( $query ) {
        global $pagenow;

        if( $query->is_admin && $pagenow == 'edit.php' && isset( $_GET['filter_laskuhari_status'] ) && $_GET['post_type'] == 'shop_order' ) {
            if( ! isset( $this->status_queries[$_GET['filter_laskuhari_status']] ) ) {
                return;
            }
            $status_query = $this->status_queries[$_GET['filter_laskuhari_status']];
            $query->set( 'meta_query', $status_query );
        }

    }
}
