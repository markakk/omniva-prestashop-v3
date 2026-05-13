(function () {
    var cfg = window.omnivaBlockParams || {};

    function init() {
        var omnivaltPanel = document.querySelector('.omniva-block');

    var carrierSelect = document.getElementById('omniva-carrier');
    if (carrierSelect) {
        carrierSelect.addEventListener('change', function () {
            var matched = false;
            for (var key in cfg.methods) {
                if (this.value != cfg.methods[key].carrier_id) {
                    continue;
                }

                matched = true;
                toggleClass('.omniva-terminal-block', 'd-none', true);
                toggleClass('.omniva-cod-block', 'd-none', true);
                toggleClass('.omniva-additionalservices-block', 'd-none', true);

                if (key === 'pt') {
                    toggleClass('.omniva-terminal-block', 'd-none', false);
                }
                if (!cfg.methods[key].is_international) {
                    toggleClass('.omniva-cod-block', 'd-none', false);
                    toggleClass('.omniva-additionalservices-block', 'd-none', false);
                }
                break;
            }
            if (!matched) {
                toggleClass('.omniva-terminal-block', 'd-none', true);
                toggleClass('.omniva-cod-block', 'd-none', true);
                toggleClass('.omniva-additionalservices-block', 'd-none', true);
            }
        });
        carrierSelect.dispatchEvent(new Event('change'));
    }

    function toggleClass(selector, className, add) {
        document.querySelectorAll(selector).forEach(function (el) {
            el.classList.toggle(className, add);
        });
    }

    function disableButton(id, status) {
        var btn = omnivaltPanel ? omnivaltPanel.querySelector(id) : document.querySelector(id);
        if (btn) btn.disabled = status;
    }

    function cleanResponse() {
        document.querySelectorAll('.omniva-response').forEach(function (el) {
            el.classList.remove('alert-danger', 'alert-warning', 'alert-success');
            el.classList.add('d-none');
            el.innerHTML = '';
        });
    }

    function showResponse(msg, type) {
        cleanResponse();
        document.querySelectorAll('.omniva-response').forEach(function (el) {
            el.classList.remove('d-none');
            el.classList.add(type);
            el.innerHTML = msg;
        });
    }

    function getErrorText(errorType) {
        switch (errorType) {
            case 'parsererror':
                return cfg.text.ajax_parsererror;
            default:
                return cfg.text.ajax_unknownerror;
        }
    }

    function downloadLabelPdf(orderId) {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', cfg.downloadLabelsUrl + '&id_order=' + orderId, true);
        xhr.responseType = 'blob';
        xhr.onload = function () {
            if (xhr.status === 200) {
                var blob = xhr.response;
                var link = document.createElement('a');
                var url = window.URL.createObjectURL(blob);
                link.href = url;
                link.download = 'omniva_label_' + orderId + '.pdf';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                window.URL.revokeObjectURL(url);
            }
        };
        xhr.send();
    }

    function postAjax(url, data, onSuccess, onError) {
        var params = new URLSearchParams(data).toString();
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    var json = JSON.parse(xhr.responseText);
                    onSuccess(json);
                } catch (e) {
                    onError('parsererror');
                }
            } else {
                onError('servererror');
            }
        };
        xhr.onerror = function () {
            onError('networkerror');
        };
        xhr.send(params);
    }

    function labelOrderInfo() {
        disableButton('#omnivaltOrderPrintLabels', true);

        postAjax(cfg.printLabelsUrl, { ajax: '1', id_order: cfg.id_order },
            function (res) {
                disableButton('#omnivaltOrderPrintLabels', false);

                if (typeof res.error !== 'undefined') {
                    showResponse(res.error, 'alert-danger');
                    return;
                }

                showResponse(cfg.successText, 'alert-success');
                downloadLabelPdf(cfg.id_order);

                setTimeout(function () {
                    window.location.href = location.href;
                }, 2000);
            },
            function (errorType) {
                showResponse(getErrorText(errorType), 'alert-danger');
                disableButton('#omnivaltOrderPrintLabels', false);
            }
        );
    }

    function saveOrderInfo() {
        disableButton('#omnivaltOrderSubmitBtn', true);

        var form = document.getElementById('omnivaltOrderSubmitForm');
        var formData = new FormData(form);
        formData.append('ajax', '1');
        formData.append('order_id', cfg.id_order);

        var data = {};
        formData.forEach(function (value, key) {
            data[key] = value;
        });

        postAjax(cfg.moduleUrl, data,
            function (res) {
                disableButton('#omnivaltOrderSubmitBtn', false);

                if (typeof res.error !== 'undefined') {
                    showResponse(res.error, 'alert-danger');
                    return;
                }

                showResponse(cfg.text.save_success, 'alert-success');
            },
            function (errorType) {
                showResponse(getErrorText(errorType), 'alert-danger');
                disableButton('#omnivaltOrderSubmitBtn', false);
            }
        );
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('#omnivaltOrderPrintLabels')) {
            e.preventDefault();
            labelOrderInfo();
        }

        if (e.target.closest('#omnivaltOrderSubmitBtn')) {
            e.preventDefault();
            saveOrderInfo();
        }
    });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
