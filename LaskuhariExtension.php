<?php
abstract class LaskuhariExtension
{
    /**
     * Filters to be added by this extension
     *
     * @var array
     */
    protected array $filters = [];

    /**
     * Actions to be added by this extension
     *
     * @var array
     */
    protected array $actions = [];

    public function init() {
        foreach( $this->filters as $filter ) {
            $filter[1] = [ $this, $filter[1] ];
            add_filter( ...$filter );
        }

        foreach( $this->actions as $action ) {
            $action[1] = [ $this, $action[1] ];
            add_action( ...$action );
        }
    }
}
