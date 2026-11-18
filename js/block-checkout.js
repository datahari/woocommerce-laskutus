( function () {
    const { createElement: h, useState, useEffect } = window.wp.element;
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const info = window.laskuhariBlockCheckout;

    function LaskuhariPaymentForm( { eventRegistration, emitResponse } ) {
        const [eInvoiceOperator, setEInvoiceOperator] = useState( info.eInvoiceOperator || "" );
        const [invoicingMethod, setInvoicingMethod] = useState( info.invoicingMethod || "" );
        const [eInvoiceAddress, setEInvoiceAddress] = useState( info.eInvoiceAddress || "" );
        const [yourReference, setYourReference] = useState( info.yourReference || "" );
        const [businessId, setBusinessId] = useState( info.businessId || "" );
        const [email, setEmail] = useState( info.email || "" );

        useEffect( () => {
            return eventRegistration.onPaymentSetup( () => ({
                type: emitResponse.responseTypes.SUCCESS,
                meta: {
                    paymentMethodData: {
                        "laskuhari-verkkolaskuosoite": eInvoiceAddress,
                        "laskuhari-laskutustapa": invoicingMethod,
                        "laskuhari-valittaja": eInvoiceOperator,
                        "laskuhari-viitteenne": yourReference,
                        "laskuhari-ytunnus": businessId,
                        "laskuhari-email": email,
                    },
                },
            } ) );
        }, [
            eventRegistration.onPaymentSetup,
            eInvoiceOperator,
            eInvoiceAddress,
            invoicingMethod,
            yourReference,
            businessId,
            email,
        ] );

        let selected_operator_found = false;

        let operators_list = [];
        for( const [code, name] of Object.entries( info.operators.operators ) ) {
            operators_list.push( h( "option", { value: code }, name ) );

            if( code === eInvoiceOperator ) {
                selected_operator_found = true;
            }
        }

        let banks_list = [];
        for( const [code, name] of Object.entries( info.operators.banks ) ) {
            banks_list.push( h( "option", { value: code }, name ) );

            if( code === eInvoiceOperator ) {
                selected_operator_found = true;
            }
        }

        let others_list = [];
        if( ! selected_operator_found ) {
            others_list = [
                h( "optgroup", { label: info.texts.others },
                    h( "option", { value: eInvoiceOperator }, eInvoiceOperator ),
                )
            ];
        }

        return h( "div", null,
            h( "p", null, info.texts.description ),

            h( "div", { class: "laskuhari-caption" }, info.texts.invoicingMethod + ":"),

            h( "select", {
                id: "laskuhari-laskutustapa",
                class: "laskuhari-pakollinen",
                onChange: e => setInvoicingMethod( e.target.value ),
            },
                h( "option", { value: "" }, "-- " + info.texts.selectInvoicingMethod + " --" ),
                h( "option", { value: "email", selected: invoicingMethod === "email" }, info.texts.invoicingMethod_email ),
                h( "option", { value: "verkkolasku", selected: invoicingMethod === "verkkolasku" }, info.texts.invoicingMethod_eInvoice ),
                h( "option", { value: "kirje", selected: invoicingMethod === "kirje" }, info.texts.invoicingMethod_letter )
            ),

            ...( info.email !== info.user_email && invoicingMethod === "email" ? [
                h( "div", { id: "laskuhari-sahkoposti-tiedot" },
                    h( "div", { class: "laskuhari-caption" }, info.texts.email + ":" ),
                    h( "input", {
                        type: "text",
                        id: "laskuhari-email",
                        value: email,
                        onChange: e => setEmail( e.target.value ),
                    } ),
                ),
            ] : [] ),

            h( "div", { id: "laskuhari-verkkolasku-tiedot", style: ( invoicingMethod === "verkkolasku" ? {} : { display: "none" } ) },
                h( "div", { class: "laskuhari-caption" }, info.texts.businessId + ":"),
                h( "input", {
                    type: "text",
                    id: "laskuhari-ytunnus",
                    value: businessId,
                    onChange: e => setBusinessId( e.target.value ),
                } ),

                h( "div", { class: "laskuhari-caption" }, info.texts.eInvoiceAddress + ":" ),
                h( "input", {
                    type: "text",
                    id: "laskuhari-verkkolaskuosoite",
                    value: eInvoiceAddress,
                    onChange: e => setEInvoiceAddress( e.target.value ),
                } ),

                h( "div", { class: "laskuhari-caption" }, info.texts.eInvoiceOperator + ":" ),
                h( "select", {
                    type: "text",
                    id: "laskuhari-valittaja",
                    value: eInvoiceOperator,
                    onChange: e => setEInvoiceOperator( e.target.value ),
                },
                    h( "option", { value: "" }, "-- " + info.texts.selectEInvoiceOperator + " --" ),
                    h( "optgroup", { label: info.texts.operators },
                        operators_list,
                    ),
                    h( "optgroup", { label: info.texts.banks },
                        banks_list,
                    ),
                    ...others_list
                ),
            ),

            h( "div", { class: "laskuhari-caption" }, info.texts.yourReference + ":" ),
            h( "input", {
                type: "text",
                id: "laskuhari-viitteenne",
                value: yourReference,
                onChange: e => setYourReference( e.target.value ),
            } ),
        );
    }

    registerPaymentMethod( {
        name: "laskuhari",

        label: info.texts.title,
        ariaLabel: info.texts.title,

        content: h( LaskuhariPaymentForm ),

        edit: h( "p", null, info.texts.description ),

        canMakePayment: () => true,

        supports: {
            features: [ "products" ],
        },
    } );
} )();
