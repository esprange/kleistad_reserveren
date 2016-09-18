/*
 Description: java functions voor kleistad_reserveren plugin
 Version: 1.0
 Author: Eric Sprangers
 Author URI: http://www.sprako.nl/
 License: GPL2
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        
        $('.kleistad_reserveringen').each(function () {
            var oven = $(this).data('oven');
            var maand = $(this).data('maand');
            var jaar = $(this).data('jaar');
            kleistad_show(oven, maand, jaar);
        });

        $('.kleistad_verdeel').change(function () {
            kleistad_verdeel(this, $(this).data('oven'));
        });

        $("body").on("click", '.kleistad_periode', function () {
            var oven = $(this).data('oven');
            var maand = $(this).data('maand');
            var jaar = $(this).data('jaar');
            kleistad_show(oven, maand, jaar);
        });

        $("body").on("change", '.kleistad_gebruiker', function () {
            var id = $(this).data('oven');
            $('#kleistad_stoker' + id).html( $('#kleistad_gebruiker_id' + id + ' option:selected').html() );
            $('#kleistad_1e_stoker' + id).val( $('#kleistad_gebruiker_id' + id).val());
        });

        $("body").on("click", '.kleistad_box', function () {
            kleistad_form($(this).data('form'));
        });

        $("body").on("click", '.kleistad_muteer', function () {
            kleistad_muteer($(this).data('oven'), 1);
        });

        $("body").on("click", '.kleistad_verwijder', function () {
            kleistad_muteer($(this).data('oven'), -1);
        });
    });

    function kleistad_form(form_data) {
        var id = form_data.oven;
        $('#kleistad_wanneer' + id).text(form_data.dag + '-' + form_data.maand + '-' + form_data.jaar);
        $('#kleistad_temperatuur' + id).val(form_data.temperatuur);
        $('#kleistad_soortstook' + id).val(form_data.soortstook);
        $('#kleistad_dag' + id).val(form_data.dag);
        $('#kleistad_maand' + id).val(form_data.maand);
        $('#kleistad_jaar' + id).val(form_data.jaar);
        $('#kleistad_gebruiker_id' + id).val(form_data.gebruiker_id);
        $('#kleistad_programma' + id).val(form_data.programma);
        $('#kleistad_opmerking' + id).val(form_data.opmerking);
        $('#kleistad_stoker' + id).html( $('#kleistad_gebruiker_id' + id + ' option:selected').html() );
        $('#kleistad_1e_stoker' + id).val( $('#kleistad_gebruiker_id' + id).val());

        var stoker_ids = $('[name=kleistad_stoker_id' + id + ']').toArray();
        var stoker_percs = $('[name=kleistad_stoker_perc' + id + ']').toArray();

        var i = 0;

        for (i = 0; i < 5; i++) {
            stoker_ids[i].value = form_data.verdeling[i].id;
            stoker_percs[i].value = form_data.verdeling[i].perc;
        }
        if (form_data.gereserveerd == 1) {
            $('#kleistad_muteer' + id).text('Wijzig');
            if (form_data.verwijderbaar == 1) {
                $('#kleistad_tekst' + id).text('Wil je de reservering wijzigen of verwijderen ?');
                $('#kleistad_verwijder' + id).show();
            } else {
                $('#kleistad_tekst' + id).text('Wil je de reservering wijzigen ?');
                $('#kleistad_verwijder' + id).hide();
            }
        } else {
            $('#kleistad_tekst' + id).text('Wil je de reservering toevoegen ?');
            $('#kleistad_muteer' + id).text('Voeg toe');
            $('#kleistad_verwijder' + id).hide();
        }
    }

    function kleistad_verdeel(element, id) {
        var stoker_percs = $('[name=kleistad_stoker_perc' + id + ']').toArray();
        var stoker_ids = $('[name=kleistad_stoker_id' + id + ']').toArray();

        var i;
        var sum = 0;
        for (i = 1; i < stoker_percs.length; i++) {
            if (stoker_ids[i].value == '') {
                stoker_percs[i].value = 0;
            }
            sum += +stoker_percs[i].value;
        }
        if (sum > 100) {
            element.value = element.value - (sum - 100);
            sum = 100;
        }
        stoker_percs[0].value = 100 - sum;

    }

    function kleistad_falen(message) {
        // rapporteer falen
        alert(message);
    }

    function kleistad_show(id, maand, jaar) {
        jQuery.ajax({
            url: kleistad_data.base_url + '/show/',
            method: 'POST',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', kleistad_data.nonce);
            },
            data: {
                maand: maand,
                jaar: jaar,
                oven_id: id
            }
        }).done(function (data) {
            var top = $('#kleistad' + data.id).scrollTop();
            $('#reserveringen' + data.id).html(data.html);
            $('#kleistad' + data.id).scrollTop(top);
        }).fail(function (jqXHR, textStatus, errorThrown) {
            if ('undefined' != typeof jqXHR.responseJSON.message) {
                kleistad_falen(jqXHR.responseJSON.message);
                return;
            }
            kleistad_falen(kleistad_data.error_message);
        });
    }

    function kleistad_muteer(id, wijzigen) {
        self.parent.tb_remove();

        var stoker_percs = $('[name=kleistad_stoker_perc' + id + ']').toArray();
        var stoker_ids = $('[name=kleistad_stoker_id' + id + ']').toArray();
        var verdeling = {};

        for (var i = 0; i < stoker_ids.length; i++) {
            verdeling[i] = {id: stoker_ids[i].value, perc: stoker_percs[i].value};
        }

        $.ajax({
            url: kleistad_data.base_url + '/reserveer/',
            method: 'POST',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', kleistad_data.nonce);
            },
            data: {
                dag: $('#kleistad_dag' + id).val(),
                maand: $('#kleistad_maand' + id).val(),
                jaar: $('#kleistad_jaar' + id).val(),
                oven_id: wijzigen * id,
                temperatuur: $('#kleistad_temperatuur' + id).val(),
                soortstook: $('#kleistad_soortstook' + id).val(),
                gebruiker_id: $('#kleistad_gebruiker_id' + id).val(),
                programma: $('#kleistad_programma' + id).val(),
                verdeling: JSON.stringify(verdeling),
                opmerking: $('#kleistad_opmerking' + id).val()
            }
        }).done(function (data) {
            var top = $('#kleistad' + data.id).scrollTop();
            $('#reserveringen' + data.id).html(data.html);
            $('#kleistad' + data.id).scrollTop(top);
        }).fail(function (jqXHR, textStatus, errorThrown) {
            if ('undefined' != typeof jqXHR.responseJSON.message) {
                kleistad_falen(jqXHR.responseJSON.message);
                return;
            }
            kleistad_falen(kleistad_data.error_message);
        });
    }

})(jQuery);
