$(document).ready(() => {
    $('.showall').hide();
    $('.pagination_next b').hide();
    $('.pagination_previous b').hide();

    // Activate tab from URL hash (e.g. after manifest generation reload)
    if (window.location.hash) {
        var tabLink = $('.nav-tabs a[href="' + window.location.hash + '"]');
        if (tabLink.length) {
            tabLink.tab('show');
        }
    }

    // Tri-state select-all: unchecked → indeterminate (latest only) → checked (all) → unchecked
    $('.select-all').on('click', function () {
        var table = $(this).closest('table');
        var state = $(this).data('omniva-state') || 0;

        state = (state + 1) % 3;
        $(this).data('omniva-state', state);

        if (state === 0) {
            // Unchecked: deselect all
            this.checked = false;
            this.indeterminate = false;
            table.find('.selected-orders').prop('checked', false);
        } else if (state === 1) {
            // Indeterminate: select only latest (non-history) checkboxes
            this.checked = false;
            this.indeterminate = true;
            table.find('.selected-orders').not('.selected-history').prop('checked', true);
            table.find('.selected-history').prop('checked', false);
        } else {
            // Checked: select all including history
            this.checked = true;
            this.indeterminate = false;
            table.find('.selected-orders').prop('checked', true);
        }
    });

    $('.action-call').on('click', function (e) {
        var orderIds = [];
        var historyIds = [];
        $(this).closest('.tab-pane').find('.selected-orders:checked').each(function () {
            var val = $(this).val();
            if (val.indexOf('history:') === 0) {
                historyIds.push(val.replace('history:', ''));
            } else {
                orderIds.push(val);
            }
        });

        if (this.id == 'print-labels' || this.id == 'print-manifest-selected' || this.id == 'download-manifest') {
            if (orderIds.length == 0 && historyIds.length == 0) {
                alert(check_orders);
                return false;
            }
        }

        var link;
        if (this.id == 'print-labels') {
            link = bulkLabelsLink;
        } else if (this.id == 'print-manifest-selected') {
            link = manifestLink;
        } else if (this.id == 'download-manifest') {
            link = downloadManifestLink;
        }

        var params = [];
        if (orderIds.length > 0) {
            params.push('order_ids=' + orderIds.join(','));
        }
        if (historyIds.length > 0) {
            params.push('history_ids=' + historyIds.join(','));
        }

        $(this).attr('href', link + '&' + params.join('&'));

        // Reload page after manifest generation so the order list updates
        if (this.id == 'print-manifest-selected') {
            setTimeout(function () {
                var url = window.location.href.split('#')[0];
                window.location.href = url + '#tab-sent-orders';
                window.location.reload();
            }, 1000);
        }
    });

    /* Start courier call */
    $('#requestOmnivaltCourier').on('click', function (e) {
        e.preventDefault();
        $.ajax({
            url: carrier_cal_url,
            type: 'get',
            dataType: 'json',
            beforeSend: function () {
                $('#alertList').empty();
            },
            success: function (data) {
                let hide_after = 3000;
                if (data == '1')
                {
                    $('#alertList').append(
                        `<div class="alert alert-success" id="remove2">
                            <strong>${finished_trans}</strong> ${message_sent_trans}
                        </div>`
                    );
                }
                else if(typeof data.error !== 'undefined')
                {
                    hide_after = 0;
                    $('#alertList').append(
                        `<div class="alert alert-danger" id="remove2">
                                ${data.error}
                        </div>`
                    );
                }
                else if(typeof data.warning !== 'undefined')
                {
                    hide_after = 0;
                    $('#alertList').append(
                        `<div class="alert alert-warning" id="remove2">
                                ${data.warning}
                        </div>`
                    );
                }
                else if(typeof data.call_id !== 'undefined')
                {
                    $('#alertList').append(
                        `<div class="alert alert-success" id="remove2">
                            <strong>${finished_trans}</strong> ${courier_call_success} (ID: ${data.call_id}). ${courier_arrival_between} ${data.start_time} - ${data.end_time}.
                        </div>`
                    );
                    hide_after = 0;
                    $('#myModal .modal-footer button').hide();
                    $('#modalOmnivaltClose').show();
                    $('.omnivalt-courier-calls').show();
                    setTimeout(function () {
                        // Remove existing row with same call_id (API may return same ID)
                        $('#omnivalt-courier-calls-list button[data-callid="' + data.call_id + '"]').closest('tr').remove();

                        let splited_start_time = data.start_time.split(" ");
                        let splited_end_time = data.end_time.split(" ");
                        let row = `<tr>
                            <td><small>${splited_start_time[0]}</small></td>
                            <td>${splited_start_time[1]}</td>
                            <td>`;
                        if (splited_start_time[0] !== splited_end_time[0]) {
                            row += `<small>${splited_end_time[0]}</small>`
                        }
                        row += `</td>
                            <td>${splited_end_time[1]}</td>
                            <td><button class="btn btn-danger btn-xs" type="button" data-callid="${data.call_id}">&times;</button></td>
                        </tr>`;
                        if ($('#omnivalt-courier-calls-list > tbody > tr').length) {
                            $('#omnivalt-courier-calls-list tr:last').after(row);
                        } else {
                            $('#omnivalt-courier-calls-list').append('<tbody/>').append(row);
                        }
                    }, 1000);
                }
                else
                {
                    hide_after = 0;
                    $('#alertList').append(
                        `<div class="alert alert-danger" id="remove2">
                                ${incorrect_response_trans}
                        </div>`
                    );
                }

                if (hide_after > 0) {
                    setTimeout(function () {
                        $('#remove2').remove();
                        $('#myModal').modal('hide');
                    }, hide_after);
                }
            },
            error: function (xhr, ajaxOptions, thrownError) {
                var errorMsg = thrownError;
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.detail) errorMsg = resp.detail;
                } catch (e) {}
                $('#alertList').empty().append(
                    '<div class="alert alert-danger">' + errorMsg + '</div>'
                );
            }
        });
    });

    $('#modalOmnivaltClose').on('click', function (e) {
        e.preventDefault();
        $('#myModal .modal-footer button').show();
        $('#modalOmnivaltClose').hide();
        $('#remove2').remove();
    });

    $(document).on('click', '#omnivalt-courier-calls-list button', function (e) {
        e.preventDefault();
        let call_id = $(this).attr('data-callid');
        let row = $(this).closest('tr');
        if (call_id) {
            $.ajax({
                url: cancel_courier_call + call_id,
                type: 'get',
                dataType: 'json',
                beforeSend: function () {
                    $('#alertList').empty();
                },
                success: function (data) {
                    if (data) {
                        if (data.hasOwnProperty('error')) {
                            alert('Error: ' + data.error);
                            return;
                        }
                        row.find('td').css('background-color', '#f9000052');
                        setTimeout(function () {
                            row.remove();
                        }, 1000);
                    }
                },
                error: function (xhr, ajaxOptions, thrownError) {
                    alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
                }
            });
        }
    });
    /* End of courier call */

    var params ={};
    window.location.search
        .replace(/[?&]+([^=&]+)=([^&]*)/gi, function (str, key, value) {
                params[key] = value;
            }
        );
    if (params['tab'] == 'completed')
        $('[href="#tab-sent-orders"]').trigger('click');

    if (params['tab'] == 'new')
        $('[href="#tab-general"]').trigger('click');

    /* Search script */
    $('#button-search').on('click', function () {
        var tracking = $('input[name="tracking_nr"]').val();
        var customer = $('input[name="customer"]').val();
        var dateAdd = $('input[name="input-date-added"]').val();
        var orderId = $('input[name="order_id"]').val();
        var orderDate = $('input[name="input-order-date"]').val();
        $.ajax({
            url: ajaxCall,
            type: 'post',
            dataType: 'json',
            data: {
                'tracking_nr' : tracking,
                'customer' : customer,
                'input-date-added' : dateAdd,
                'order_id' : orderId,
                'input-order-date' : orderDate,
            },
            beforeSend: function () {
                $('#searchTable').empty();
            },
            success: function (data) {
                if (data != null && data[0] && Object.keys(data[0]).length > 0) {
                    datas = data;
                    for (data of datas) {
                        $('#searchTable').append(`
                            <tr>
                                <td class='left'><a href='${orderLink.replace("/0/", "/" + data["id_order"] + "/")}' target='_blank'>${data['id_order']}</a></td>
                                <td>${data['full_name']}</td>
                                <td>${data['date_add'] ? data['date_add'].substring(0, 10) : ''}</td>
                                <td>${data['tracking_numbers']}</td>
                                <td>${data['labels_registered'] ? data['labels_registered'] : ''}</td>
                                <td>${data['total_paid_tax_incl']}</td>
                                <td><a href='${labelsLink}&id_order=${data['id_order']}&history=${data['history']}' class='btn btn-default btn-xs' target='_blank'>${labels_trans}</a></td>
                            </tr>`
                        );
                    }
                } else
                    $('#searchTable').append(`<tr><td colspan='7'>${not_found_trans}</td>`);
            },
            error: function (xhr, ajaxOptions, thrownError) {
            }
        });
    });
});