define([
    'jquery',
    'ko',
    'uiComponent',
    'underscore',
    'uiLayout'
], function ($, ko, Component, _, layout) {

    return Component.extend({
        defaults: {
            //template: 'SITC_Sinchimport/import_status',
            //selector: '[data-role=sinchimport-status]',
            updateURL: ''
        },

        statuses: ko.observableArray([]),

        initialize: function() {
            this._super()
                .initObservable();

            //Bind updateEvent to the context of this object
            _.bindAll(this, 'updateEvent');
            this.updateTimer = setInterval(this.updateEvent, 5000);

            return this;
        },

        updateEvent: function () {
            _this = this;
            $.ajax(this.updateURL, {
                method: 'GET',
                dataType: 'json',
                data: {
                    form_key: FORM_KEY
                },
                success: function(data) {
                    if (!data.error) {
                        _this.statuses(data);
                    } else {
                        console.log("Error response: " + data);
                    }
                },
                error: function() {
                    //document.location.reload();
                },
            });
        }
    });
});
