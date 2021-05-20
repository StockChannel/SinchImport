define([
    'jquery',
    'ko',
    'uiComponent',
    'underscore',
    'uiLayout'
], function ($, ko, Component, _, layout) {

    return Component.extend({
        defaults: {
            parent: '${ $.name }',
            nodeTemplate: 'SITC_Sinchimport/import_status',
            //selector: '[data-role=sinchimport-status]',
            updateURL: '',
            completeIcon: '',
            runningIcon: ''
        },

        initialize: function() {
            this._super()
                .initObservable();
            layout([this.defaults]);

            this.statuses = ko.observableArray([]);
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
        },

        updateStatusHtml: function(objectMsg){
            let mess_id = 'sinchimport_' + objectMsg.message.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9]/g, '');
            let status_entry = document.getElementById(mess_id);
            if(!status_entry){
                let status_table = document.getElementById('sinchimport_status_table_body');
                let new_row = document.createElement('tr');
                status_table.appendChild(new_row);

                let title_data = document.createElement('td');
                title_data.innerText = objectMsg.message;
                new_row.appendChild(title_data);

                let status_data = document.createElement('td');
                status_data.id = mess_id;
                new_row.appendChild(status_data);
                status_entry = status_data;
            }
            status_entry.innerHTML = objectMsg.finished == 1 ? '<img src=\"" . $completeIcon . "\" alt=\"Complete\"/>' : '<img src=\"" . $runningIcon . "\" alt=\"Running\"/>';
        },
    });
});
