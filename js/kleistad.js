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
        $(document).tooltip();

        $(".kleistad_tijd").each(function () {
            $(this).timeEntry({
                show24Hours: (true),
                spinnerImage: ("")
            }
            );
        });

        $('#kleistad_cursus').dialog({
            autoOpen: false,
            height: 500,
            width: 750,
            modal: false,
            open: function (event, ui) {
                $('#kleistad_cursus_tabs').tabs({ active : 0 });
            }
        });

        $('#kleistad_deelnemer_info').dialog({
            autoOpen: false,
            height: 400,
            width: 750,
            modal: true,
            buttons: {
                Ok: function() {
                  $( this ).dialog('close');
              }
            }
        });
        
        $('.kleistad_deelnemer_info').click(function() {
            $('#kleistad_deelnemer_info').dialog('open');
            var inschrijvingen = $(this).data('inschrijvingen');
            var deelnemer = $(this).data('deelnemer');
            $('#kleistad_deelnemer_tabel').empty();
            $('#kleistad_deelnemer_tabel').append('<tr><th colspan="6">' + deelnemer + '</th></tr>');
            var header = '<tr><th>Cursus</th><th>Code</th><th>Ingedeeld</th><th>Inschrijfgeld<br/>voldaan</th><th>Cursusgeld<br/>voldaan</th><th>Technieken</th></tr>';
            if (typeof inschrijvingen !== 'undefined') {
                $.each(inschrijvingen, function(key, value) {
                    var status = (value['ingedeeld']) ? '<span class="dashicons dashicons-yes"></span>' : '';
                    var i_betaald = (value['i_betaald']) ? '<span class="dashicons dashicons-yes"></span>' : '';
                    var c_betaald = (value['c_betaald']) ? '<span class="dashicons dashicons-yes"></span>' : '';
                    
                    var html = header + '<tr><td>' + value['naam'] + '</td><td>' + value['code'] + '</td><td>' + status + '</td><td>' + i_betaald + '</td><td>' + c_betaald + '</td><td>';
                    header = '';
                    var separator = '';
                    $.each(value['technieken'], function(key, value) {
                        html += separator + value;
                        separator = '<br/>';
                    });
                    $('#kleistad_deelnemer_tabel').append( html + '</td></tr>');
                });
            } else {
                $('#kleistad_deelnemer_tabel').append('<tr><td colspan="6">Geen cursus inschrijvingen aanwezig</td></tr>');
            }
        });
//        $(function () {
//            $("#dialog").dialog();
//            $("#ui-dialog-title-dialog").hide();
//            $(".ui-dialog-titlebar").removeClass('ui-widget-header');
//        });

        $('.kleistad_cursus_beheer').click(function() {
            $('.kleistad_fout').empty();
            $('.kleistad_succes').empty();
            $('#kleistad_cursus').dialog('open');
            var cursus = $(this).data('cursus');
            var wachtlijst = $(this).data('wachtlijst');
            var ingedeeld = $(this).data('ingedeeld');
            $('#kleistad_cursus_id_1').val(cursus.id);
            $('#kleistad_cursus_id_2').val(cursus.id);
            $('#kleistad_cursus_naam').val(cursus.naam);
            $('#kleistad_cursus_docent').val(cursus.docent);
            $('#kleistad_cursus_start_datum').val(cursus.start_datum);
            $('#kleistad_cursus_eind_datum').val(cursus.eind_datum);
            $('#kleistad_cursus_start_tijd').val(cursus.start_tijd.substr(0,5));
            $('#kleistad_cursus_eind_tijd').val(cursus.eind_tijd.substr(0,5));
            $('#kleistad_cursuskosten').val(cursus.cursuskosten);
            $('#kleistad_inschrijfkosten').val(cursus.inschrijfkosten);
            $('#kleistad_inschrijfslug').val(cursus.inschrijfslug);
            $('#kleistad_indelingslug').val(cursus.indelingslug);
            $('#kleistad_draaien').prop("checked", String(cursus.technieken).indexOf('Draaien') >= 0);
            $('#kleistad_handvormen').prop("checked", String(cursus.technieken).indexOf('Handvormen') >= 0);
            $('#kleistad_boetseren').prop("checked", String(cursus.technieken).indexOf('Boetseren') >= 0);
            $('#kleistad_techniekkeuze').prop("checked", cursus.techniekkeuze > 0);
            $('#kleistad_vol').prop("checked", cursus.vol > 0);
            $('#kleistad_vervallen').prop("checked", cursus.vervallen > 0);
            $('#kleistad_wachtlijst').children().remove().end();
            $.each(wachtlijst, function(key, value) {
                $('#kleistad_wachtlijst').append($('<option value="' + key + '" data-ingedeeld="0" data-opmerking="' + value['opmerking'] + '" data-technieken=\'' + value['technieken'] + '\' >' + value['naam'] + '</option>'));
            });
            $('#kleistad_indeling').children().remove().end();
            $.each(ingedeeld, function(key, value) {
                $('#kleistad_indeling').append($('<option style="background-color:lightgreen;font-weight:bold;" value="' + key + '" data-ingedeeld="1" data-opmerking="' + value['opmerking'] + '" data-technieken=\'' + value['technieken'] + '\' >' + value['naam'] + '</option>'));
            });
        });
        
        $('#kleistad_cursus_toevoegen').click(function () {
            $('.kleistad_fout').empty();
            $('.kleistad_succes').empty();
            $('#kleistad_cursus').dialog('open');
            $('#kleistad_cursus_id_1').removeAttr('value');
            $('#kleistad_cursus_id_2').removeAttr('value');
            $('#kleistad_cursus_naam').removeAttr('value');
            $('#kleistad_cursus_docent').removeAttr('value');
            $('#kleistad_cursus_start_datum').removeAttr('value');
            $('#kleistad_cursus_eind_datum').removeAttr('value');
            $('#kleistad_cursus_start_tijd').removeAttr('value');
            $('#kleistad_cursus_eind_tijd').removeAttr('value');
            $('#kleistad_cursuskosten').prop('defaultValue');;
            $('#kleistad_inschrijfkosten').prop('defaultValue');
            $('#kleistad_inschrijfslug').prop('defaultValue');
            $('#kleistad_indelingslug').prop('defaultValue');
            $('#kleistad_draaien').prop("checked", false);
            $('#kleistad_handvormen').prop("checked", false);
            $('#kleistad_boetseren').prop("checked", false);
            $('#kleistad_techniekkeuze').prop("checked", false);
            $('#kleistad_vol').prop("checked", false);
            $('#kleistad_vervallen').prop("checked", false);
            $('#kleistad_wachtlijst').children().remove().end();
            $('#kleistad_indeling').children().remove().end();
        });
        
        $('input[name=cursus_id]:radio').change(function () {
            var technieken = $(this).data('technieken');
            $('#kleistad_cursus_draaien').css('visibility', 'hidden');
            $('#kleistad_cursus_boetseren').css('visibility', 'hidden');
            $('#kleistad_cursus_handvormen').css('visibility', 'hidden');
            $('#kleistad_cursus_technieken').css('visibility','hidden'); 
            
            $.each(technieken, function(key, value){
               $('#kleistad_cursus_' + value.toLowerCase()).css('visibility','visible').find('input').prop('checked', false)
               $('#kleistad_cursus_technieken').css('visibility','visible'); 
            });
        });

        $('#kleistad_bewaar_cursus_indeling').click(function () {
            var options = $('#kleistad_indeling option');
            var cursisten = $.map(options ,function(option) {
                return Number(option.value);
            });
            $('#kleistad_indeling_lijst').val(JSON.stringify(cursisten));
        });

        $('#kleistad_wissel_indeling').click(function () {
            var ingedeeld = $('#kleistad_indeling option:selected');
            var wachtend = $('#kleistad_wachtlijst option:selected');
            if (ingedeeld.length) {
                if (ingedeeld.data('ingedeeld') === 0) {
                    return !ingedeeld.remove().appendTo('#kleistad_wachtlijst');
                }
            }
            if (wachtend.length) {
                return !wachtend.remove().appendTo('#kleistad_indeling');
            }
            return false;
        });

        $('#kleistad_wachtlijst').click(function () {
            $('#kleistad_indeling option:selected').prop('selected', false);
            $('#kleistad_cursist_technieken').empty();
            $('#kleistad_cursist_opmerking').empty();
            var cursist = $('option:selected', this);
            if (cursist.length) {
                kleistad_toon_cursist(cursist)
            }
        });

        $('#kleistad_indeling').click(function () {
            $('#kleistad_wachtlijst option:selected').prop('selected', false);
            $('#kleistad_cursist_technieken').empty();
            $('#kleistad_cursist_opmerking').empty();
            var cursist = $('option:selected', this);
            if (cursist.length) {
                kleistad_toon_cursist(cursist);
            }
        });

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
            $('#kleistad_stoker' + id).html($('#kleistad_gebruiker_id' + id + ' option:selected').html());
            $('#kleistad_1e_stoker' + id).val($('#kleistad_gebruiker_id' + id).val());
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
    
    function kleistad_toon_cursist(cursist) {
        var opmerking = cursist.data('opmerking');
        var technieken = cursist.data('technieken');

        if (technieken.length > 0) {
            var techniektekst = '<p>Gekozen technieken : ';
            $.each(technieken, function(key, value) {
                techniektekst += value + ' ';
            });
            techniektekst += '</p>';
            $('#kleistad_cursist_technieken').html(techniektekst);
        }
        if (opmerking.length > 0) {
            $('#kleistad_cursist_opmerking').html('<p>Opmerking : ' + opmerking + '</p>');
        }                
    }

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
        $('#kleistad_stoker' + id).html($('#kleistad_gebruiker_id' + id + ' option:selected').html());
        $('#kleistad_1e_stoker' + id).val($('#kleistad_gebruiker_id' + id).val());

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
            if (stoker_ids[i].value == '0') {
                stoker_percs[i].value = 0;
            }
            sum += +stoker_percs[i].value;
        }
        if (sum > 100) {
            element.value = element.value - (sum - 100);
            sum = 100;
        } else {
            element.value = +element.value;
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
            verdeling[i] = {id: +stoker_ids[i].value, perc: +stoker_percs[i].value};
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
