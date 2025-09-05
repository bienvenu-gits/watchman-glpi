/**
 * Gestionnaire JavaScript pour les alertes Watchman
 */
 const LOCAL_SEVERITY_COLOR = {
      LOW: {
        "-": {
          light: { bg: "#007AB978", color: "#007AC9" },
          dark: { bg: "#007AB978", color: "#007AC9" },
        },
        "N/A": {
          light: { bg: "#007AB978", color: "#007AC9" },
          dark: { bg: "#007AB978", color: "#007AC9" },
        },
        light: { bg: "#C6F6D5", color: "#22543D" },
        dark: { bg: "rgba(154, 230, 180, 0.16)", color: "#9ae6b4" },
      },
      MEDIUM: {
        light: { bg: "#FEEBC8", color: "#7B341E" },
        dark: { bg: "rgba(251, 211, 141, 0.16)", color: "#fbd38d" },
      },
      HIGH: {
        light: { bg: "#FED7D7", color: "#822727" },
        dark: { bg: "rgba(254, 178, 178, 0.16)", color: "#feb2b2" },
      },
      CRITICAL: {
        dark: { bg: "rgba(182, 38, 38, 0.36)", color: "rgba(255, 0, 0, 0.57)" },
        light: { bg: "rgba(186, 51, 51, 0.68)", color: "#900000" },
      },
    };
  
    const severity_color = (val) => {
      if (val > 0 && val < 4) {
        return "#007AB9";
      } else if (val >= 4 && val < 7) {
        return "#FABB18";
      } else if (val >= 7 && val < 9) {
        return "#FF7777";
      } else if (val >= 9 && val <= 10) {
        return "#FF0000";
      } else {
        return "#00B295";
      }
    };
    const severity_text_convert = (val) => {
      if (val=='Faible') {
        return "LOW";
      } else if (val=='Moyenne') {
        return "MEDIUM";
      } else if (val=='Élevée') {
        return "HIGH";
      } else if (val=='Critique') {
        return "CRITICAL";
      } else {
        return "N/A";
      }
    };
class WatchmanAlertsManager {
    constructor() {
        this.currentPage = 1;
        this.perPage = 20;
        this.filters = {
            search: '',
            severity: '',
            patched: '',
            date_from: '',
            date_to: '',
            order: 'date_creation',
            sort: 'DESC'
        };
        this.selectedAlerts = new Set();
        this.isLoading = false;
        
        this.init();
    }
    
    /**
     * Initialisation du gestionnaire
     */
    init() {
        this.bindEvents();
        this.loadStats();
        this.loadAlerts();
        this.initDateRangePicker();
    }
    
    /**
     * Liaison des événements
     */
    bindEvents() {
        // Recherche
        $('#cms_watchman_search_alert_input_text').on('input', debounce(() => {
            this.filters.search = $('#cms_watchman_search_alert_input_text').val().trim();
            this.resetPagination();
            this.loadAlerts();
        }, 500));
        
        // Filtres
        $('#cms_watchman_search_alert_input_patch').on('change', () => {
            this.filters.patched = $('#cms_watchman_search_alert_input_patch').val();
            this.resetPagination();
            this.loadAlerts();
        });
        
        $('#cms_watchman_search_alert_input_severity').on('change', () => {
            this.filters.severity = $('#cms_watchman_search_alert_input_severity').val();
            this.resetPagination();
            this.loadAlerts();
        });
        
        // Boutons de recherche et reset
        $('#cms_watchman_search_alert_btn').on('click', () => {
            this.applyFilters();
        });
        
        $('#cms_watchman_reset_search_alert_btn').on('click', () => {
            this.resetFilters();
        });
        
        // Pagination
        $('.cms_watchman_pagination_preview_button').on('click', () => {
            if (this.currentPage > 1) {
                this.currentPage--;
                this.loadAlerts();
            }
        });
        
        $('.cms_watchman_pagination_next_button').on('click', () => {
            this.currentPage++;
            this.loadAlerts();
        });
        
        // Sélection multiple
        $('#select-all').on('change', (e) => {
            this.toggleAllAlerts(e.target.checked);
        });
        
        // Actions sur les alertes (délégation d'événements)
        $(document).on('click', '.alert-checkbox', (e) => {
            this.toggleAlertSelection(e.target.dataset.alertId, e.target.checked);
        });
        
        $(document).on('click', '.patch-alert-btn', (e) => {
            e.preventDefault();
            const alertId = e.target.closest('button').dataset.alertId;
            const currentStatus = e.target.closest('button').dataset.patched === '1';
            this.togglePatchStatus(alertId, !currentStatus);
        });
        
        $(document).on('click', '.create-ticket-btn', (e) => {
            e.preventDefault();
            const alertId = e.target.closest('button').dataset.alertId;
            this.createTicket(alertId);
        });
        
        $(document).on('click', '.delete-alert-btn', (e) => {
            e.preventDefault();
            const alertId = e.target.closest('button').dataset.alertId;
            this.deleteAlert(alertId);
        });

        $(document).on('click', '#cms_watchman_start_cron_btn', (e) => {
            e.preventDefault();
            
            this.startAlertCron();
        });
        
        // Actions en masse
        $(document).on('click', '.bulk-action-btn', (e) => {
            e.preventDefault();
            const action = e.target.dataset.action;
            this.performBulkAction(action);
        });
    }
    
    /**
     * Initialise le sélecteur de plage de dates
     */
    initDateRangePicker() {
        // Initialisation d'un date range picker (ex: flatpickr)
        if (typeof flatpickr !== 'undefined') {
            flatpickr('#cms_watchman__search_alert_date_range', {
                mode: 'range',
                dateFormat: 'Y-m-d',
                onChange: (selectedDates) => {
                    if (selectedDates.length === 2) {
                        this.filters.date_from = selectedDates[0].toISOString().split('T')[0];
                        this.filters.date_to = selectedDates[1].toISOString().split('T')[0];
                        this.resetPagination();
                        this.loadAlerts();
                    }
                }
            });
        }
    }
    
    /**
     * Charge les statistiques
     */
    async loadStats() {
        try {
            const response = await this.makeRequest('get_stats');
            if (response.success) {
                this.updateStatsDisplay(response.data);
            }
        } catch (error) {
            console.error('Erreur lors du chargement des statistiques:', error);
        }
    }
    
    /**
     * Met à jour l'affichage des statistiques
     */
    updateStatsDisplay(stats) {
        $('#cms_watchman_alert_statistic_today_value').text(stats.today || 0);
        $('#cms_watchman_alert_statistic_total_value').text(stats.total || 0);
        $('#cms_watchman_alert_statistic_this_week_value').text(stats.this_week || 0);
        $('#cms_watchman_alert_statistic_patched_value').text(stats.patched || 0);
    }
    
    /**
     * Charge les alertes
     */
    async loadAlerts() {
        if (this.isLoading) return;
        
        this.isLoading = true;
        this.showLoading(true);
        
        try {
            const params = new URLSearchParams({
                page: this.currentPage,
                per_page: this.perPage,
                ...this.filters
            });
            
            const response = await this.makeRequest('get_alerts', params);
            
            if (response.success) {
                this.displayAlerts(response.data);
                this.updatePagination(response.pagination);
                
                if (response.data.length === 0 && this.currentPage === 1) {
                    this.showEmptyState(true);
                    this.showMainContent(false);
                } else {
                    this.showEmptyState(false);
                    this.showMainContent(true);
                }
            } else {
                throw new Error(response.error || 'Erreur inconnue');
            }
            
        } catch (error) {
            console.error('Erreur lors du chargement des alertes:', error);
            this.showNotification('Erreur lors du chargement des alertes', 'error');
        } finally {
            this.isLoading = false;
            this.showLoading(false);
        }
    }
    
    /**
     * Affiche les alertes dans le tableau
     */
    displayAlerts(alerts) {
        const tbody = $('#cms_watchman_alerts_table tbody');
        tbody.empty();
        
        if (alerts.length === 0) {
            tbody.append(`
                <tr>
                    <td colspan="9" class="text-center">Aucune alerte trouvée</td>
                </tr>
            `);
            return;
        }
        
        alerts.forEach(alert => {
            const row = this.createAlertRow(alert);
            tbody.append(row);
        });
        
        // Mise à jour de l'état des checkboxes
        this.updateCheckboxes();
    }
    
    /**
     * Crée une ligne d'alerte
     */
    createAlertRow(alert) {
        const isSelected = this.selectedAlerts.has(alert.id);
        const { bg, color } =
              LOCAL_SEVERITY_COLOR[severity_text_convert(alert.severity)]["light"];
        const patchedBadge = alert.patched.is_patched 
            ? `<span class="cms_watchman_badge cms_watchman_badge_${alert.patched.class}">${alert.patched.label}</span>`
            : `<span class="cms_watchman_badge cms_watchman_badge_${alert.patched.class}">${alert.patched.label}</span>`;
            
        const ticketLink = alert.ticket 
            ? `<a href="${alert.ticket.url}" target="_blank" class="btn btn-sm btn-info">
                 <i class="fa fa-external-link"></i> #${alert.ticket.id}
               </a>`
            : `<button class="cms_watchman_btn btn-sm cms_watchman_button_success create-ticket-btn" data-alert-id="${alert.id}">
                 <i class="fa fa-plus"></i> Créer
               </button>`;
               
        const patchButton = alert.patched.is_patched
            ? `<button class="cms_watchman_btn btn-sm cms_watchman_btn-warning patch-alert-btn" 
                       data-alert-id="${alert.id}" data-patched="1">
                 <i class="fa fa-times"></i> Annuler
               </button>`
            : `<button class="cms_watchman_btn btn-sm cms_watchman_btn-success patch-alert-btn" 
                       data-alert-id="${alert.id}" data-patched="0">
                 <i class="fa fa-check"></i> Corriger
               </button>`;
              const  view_action = `
              <a href="?alert_id=${alert.id}" class="cms_watchman_btn cms_watchman_btn_light" title="view alert detail" > <i  class="fa fa-eye cms_watchman_text_primary" ></i> </a>
              `;
        const delete_action=`
        <button class="cms_watchman_btn cms_watchman_btn_light delete-alert-btn text-danger" 
                                    data-alert-id="${alert.id}">
                                <i class="fa fa-trash"></i>
                            </button>
        `;
        return `
            <tr class="alert-row ${isSelected ? 'selected' : ''}" data-alert-id="${alert.id}">
                <td>
                    <input type="checkbox" class="alert-checkbox" 
                           data-alert-id="${alert.id}" ${isSelected ? 'checked' : ''}>
                </td>
                <td>
                    <strong>${alert.cve_id}</strong>
                    ${alert.cve_info && alert.cve_info.age_days ? 
                      `<small class="text-muted">(${alert.cve_info.age_days}j)</small>` : ''}
                </td>
                <td>
                    <div class="alert-title" title="${alert.description}">
                        ${alert.title}
                    </div>
                    ${alert.stack_info ? 
                      `<small class="text-muted">${alert.stack_info.display_name}</small>` : ''}
                </td>
                <td>
                    <span class="cms_watchman_badge" style="background:${bg};color:${color}">
                        ${alert.score || 'N/A'}
                    </span>
                </td>
                <td>
                    <span class="cms_watchman_badge" style="background:${bg};color:${color}">
                        ${alert.severity}
                    </span>
                </td>
                <td>${patchedBadge}</td>
                <td>${ticketLink}</td>
                <td>
                    <div>${alert.date_creation}</div>
                    <small class="text-muted">${alert.date_relative}</small>
                </td>
                <td>
                    <div class="btn-group" role="group">

                ${view_action}
                
                    
                    </div>
                </td>
            </tr>
        `;
    }
    
    /**
     * Met à jour la pagination
     */
    updatePagination(pagination) {
        const paginationContainer = $('#cms_watchman_pagination_alerts');
        const totalElement = paginationContainer.find('.cms_watchman_pagination_total');
        const numberElement = paginationContainer.find('.cms_watchman_pagination_number');
        const prevButton = paginationContainer.find('.cms_watchman_pagination_preview_button');
        const nextButton = paginationContainer.find('.cms_watchman_pagination_next_button');
        
        // Mise à jour du texte total
        totalElement.text(`Total: ${pagination.total} alerte(s)`);
        
        // Mise à jour du numéro de page
        numberElement.text(`${pagination.current_page} / ${pagination.total_pages}`);
        
        // Gestion des boutons
        prevButton.prop('disabled', !pagination.has_prev);
        nextButton.prop('disabled', !pagination.has_next);
        
        // Mise à jour de la page courante
        this.currentPage = pagination.current_page;
    }
    
    /**
     * Bascule le statut de correction d'une alerte
     */
    async togglePatchStatus(alertId, patched) {
        try {
            const response = await this.makeRequest('mark_as_patched', null, 'POST', {
                alert_id: alertId,
                patched: patched ? 1 : 0,
                _glpi_csrf_token: window.csrf_token
            });
            
            if (response.success) {
                this.showNotification(response.message, 'success');
                this.loadAlerts();
                this.loadStats();
            } else {
                throw new Error(response.error || 'Erreur inconnue');
            }
        } catch (error) {
            console.error('Erreur lors de la mise à jour:', error);
            this.showNotification('Erreur lors de la mise à jour du statut', 'error');
        }
    }
    
    /**
     * Crée un ticket pour une alerte
     */
    async createTicket(alertId) {
        if (!confirm('Voulez-vous créer un ticket pour cette alerte ?')) {
            return;
        }
        
        try {
            const response = await this.makeRequest('create_ticket', null, 'POST', {
                alert_id: alertId,
                _glpi_csrf_token: window.csrf_token
            });
            
            if (response.success) {
                this.showNotification(response.message, 'success');
                this.loadAlerts();
                
                // Ouvrir le ticket dans un nouvel onglet
                if (response.ticket_url) {
                    window.open(response.ticket_url, '_blank');
                }
            } else {
                this.showNotification(response.message || 'Erreur lors de la création du ticket', 'warning');
            }
        } catch (error) {
            console.error('Erreur lors de la création du ticket:', error);
            this.showNotification('Erreur lors de la création du ticket', 'error');
        }
    }
    
    /**
     * Supprime une alerte
     */
    async deleteAlert(alertId) {
        if (!confirm('Voulez-vous vraiment supprimer cette alerte ? Cette action est irréversible.')) {
            return;
        }
        
        try {
            const response = await this.makeRequest('delete_alert', null, 'POST', {
                alert_id: alertId,
                _glpi_csrf_token: window.csrf_token
            });
            
            if (response.success) {
                this.showNotification(response.message, 'success');
                this.selectedAlerts.delete(alertId);
                this.loadAlerts();
                this.loadStats();
            } else {
                throw new Error(response.error || 'Erreur inconnue');
            }
        } catch (error) {
            console.error('Erreur lors de la suppression:', error);
            this.showNotification('Erreur lors de la suppression', 'error');
        }
    }


    /**
     * 
     * start cron
     */

    async startAlertCron() {
        if(!confirm('Voulez-vous vraiment lancer la synchronisation des alertes ? Cette action peut prendre plusieurs minutes.')) {
            return;
        }
        try {
           const response=await this.makeRequest('start_cron', null, 'POST', {
                _glpi_csrf_token: window.csrf_token
            });

            console.log('response',response);
            this.showNotification('La synchronisation des alertes a été lancée. Veuillez actualiser la page après quelques minutes pour voir les nouvelles alertes.', 'success');
            // console.log(response);
        } catch (error) {
            console.error('Erreur lors de la suppression:', error);
            this.showNotification('Erreur lors de la suppression', 'error');
        }
    }


     /**
     * Affiche une notification
     */
    showNotification(message, type = 'info') {
        // Utilisation d'une notification simple ou intégration avec le système de notification de GLPI
        const alertClass = type === 'success' ? 'alert-success' : 
                          type === 'error' ? 'alert-danger' : 'alert-info';
        
        const notification = $(`
            <div class="alert ${alertClass} alert-dismissible fade show" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px;">
                ${message}
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        `);
        
        $('body').append(notification);
        
        // Auto-suppression après 5 secondes
        setTimeout(() => {
            notification.fadeOut(() => notification.remove());
        }, 5000);
    }
    
    /**
     * Sélectionne/désélectionne toutes les alertes
     */
    toggleAllAlerts(checked) {
        $('.alert-checkbox').prop('checked', checked);
        
        if (checked) {
            $('.alert-checkbox').each((index, checkbox) => {
                this.selectedAlerts.add(checkbox.dataset.alertId);
            });
        } else {
            this.selectedAlerts.clear();
        }
        
        this.updateBulkActions();
        this.updateCheckboxes();
    }
    
    /**
     * Sélectionne/désélectionne une alerte
     */
    toggleAlertSelection(alertId, checked) {
        if (checked) {
            this.selectedAlerts.add(alertId);
        } else {
            this.selectedAlerts.delete(alertId);
        }
        
        // Mise à jour du checkbox "select all"
        const totalCheckboxes = $('.alert-checkbox').length;
        const checkedCheckboxes = $('.alert-checkbox:checked').length;
        
        $('#select-all').prop('indeterminate', checkedCheckboxes > 0 && checkedCheckboxes < totalCheckboxes);
        $('#select-all').prop('checked', checkedCheckboxes === totalCheckboxes);
        
        this.updateBulkActions();
        this.updateCheckboxes();
    }
    
    /**
     * Met à jour l'affichage des actions en masse
     */
    updateBulkActions() {
        const hasSelection = this.selectedAlerts.size > 0;
        const bulkContainer = $('.bulk-actions-container');
        
        if (hasSelection && bulkContainer.length === 0) {
            // Créer le conteneur d'actions en masse
            const bulkHtml = `
                <div class="bulk-actions-container alert alert-info">
                    <strong>${this.selectedAlerts.size} alerte(s) sélectionnée(s)</strong>
                    <div class="bulk-actions-buttons">
                        <button class="btn btn-sm btn-success bulk-action-btn" data-action="mark_patched">
                            <i class="fa fa-check"></i> Marquer comme corrigées
                        </button>
                        <button class="btn btn-sm btn-warning bulk-action-btn" data-action="mark_unpatched">
                            <i class="fa fa-times"></i> Marquer comme non corrigées
                        </button>
                        <button class="btn btn-sm btn-danger bulk-action-btn" data-action="delete">
                            <i class="fa fa-trash"></i> Supprimer
                        </button>
                    </div>
                </div>
            `;
            $('.cms_watchman_table_container').prepend(bulkHtml);
        } else if (hasSelection) {
            // Mettre à jour le nombre
            $('.bulk-actions-container strong').text(`${this.selectedAlerts.size} alerte(s) sélectionnée(s)`);
        } else {
            // Supprimer le conteneur
            $('.bulk-actions-container').remove();
        }
    }
    
    /**
     * Effectue une action en masse
     */
    async performBulkAction(action) {
        const alertIds = Array.from(this.selectedAlerts);
        
        if (alertIds.length === 0) {
            this.showNotification('Aucune alerte sélectionnée', 'warning');
            return;
        }
        
        const actionLabels = {
            'mark_patched': 'marquer comme corrigées',
            'mark_unpatched': 'marquer comme non corrigées',
            'delete': 'supprimer'
        };
        
        const actionLabel = actionLabels[action] || action;
        
        if (!confirm(`Voulez-vous ${actionLabel} ${alertIds.length} alerte(s) ?`)) {
            return;
        }
        
        try {
            const response = await this.makeRequest('bulk_action', null, 'POST', {
                bulk_action: action,
                alert_ids: alertIds,
                _glpi_csrf_token: window.csrf_token
            });
            
            if (response.success) {
                this.showNotification(response.message, 'success');
                this.selectedAlerts.clear();
                this.loadAlerts();
                this.loadStats();
                this.updateBulkActions();
            } else {
                throw new Error(response.error || 'Erreur inconnue');
            }
        } catch (error) {
            console.error('Erreur lors de l\'action en masse:', error);
            this.showNotification('Erreur lors de l\'action en masse', 'error');
        }
    }
    
    /**
     * Met à jour l'état des checkboxes
     */
    updateCheckboxes() {
        $('.alert-row').each((index, row) => {
            const alertId = row.dataset.alertId;
            const isSelected = this.selectedAlerts.has(alertId);
            $(row).toggleClass('selected', isSelected);
        });
    }
    
    /**
     * Applique les filtres
     */
    applyFilters() {
        const dateRange = $('#cms_watchman__search_alert_date_range').val();
        if (dateRange && dateRange.includes(' to ')) {
            const [dateFrom, dateTo] = dateRange.split(' to ');
            this.filters.date_from = dateFrom;
            this.filters.date_to = dateTo;
        }
        
        this.resetPagination();
        this.loadAlerts();
    }
    
    /**
     * Remet à zéro les filtres
     */
    resetFilters() {
        this.filters = {
            search: '',
            severity: '',
            patched: '',
            date_from: '',
            date_to: '',
            order: 'date_creation',
            sort: 'DESC'
        };
        
        // Reset des champs
        $('#cms_watchman_search_alert_input_text').val('');
        $('#cms_watchman_search_alert_input_patch').val('');
        $('#cms_watchman_search_alert_input_severity').val('');
        $('#cms_watchman__search_alert_date_range').val('');
        
        this.resetPagination();
        this.loadAlerts();
    }
    
    /**
     * Remet à zéro la pagination
     */
    resetPagination() {
        this.currentPage = 1;
    }
    
    /**
     * Effectue une requête AJAX
     */
    async makeRequest(action, params = null, method = 'GET', data = null) {
        // const url = new URL(`${window.location.origin}${window.location.pathname.replace(/\/[^\/]*$/, '')}/ajax/alerts.php`);
        const urlStr=`${window.location.origin}${WATCHMAN_CONFIG.ajaxUrl}`;
        console.log(urlStr);
        const url = new URL(`${urlStr}`);
        url.searchParams.set('action', action);
        
        if (params && method === 'GET') {
            for (const [key, value] of params.entries()) {
                if (value !== '' && value !== null) {
                    url.searchParams.set(key, value);
                }
            }
        }
        
        const options = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        };
        
        if (method === 'POST' && data) {
            const formData = new FormData();
            for (const [key, value] of Object.entries(data)) {
                formData.append(key, value);
            }
            options.body = formData;
        }
        
        const response = await fetch(url, options);
        // if(action==='start_cron'){
        //     const response_text=await response.text();
        //     console.log(response_text);
        // }
        
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        return await response.json();
    }



    /**
     * Effectue une requête AJAX
     */
    async makeAsyncRequest(action, params = null, method = 'GET', data = null) {
        // const url = new URL(`${window.location.origin}${window.location.pathname.replace(/\/[^\/]*$/, '')}/ajax/alerts.php`);
        const urlStr=`${window.location.origin}${WATCHMAN_CONFIG.ajaxUrl}`;
        console.log(urlStr);
        const url = new URL(`${urlStr}`);
        url.searchParams.set('action', action);
        
        if (params && method === 'GET') {
            for (const [key, value] of params.entries()) {
                if (value !== '' && value !== null) {
                    url.searchParams.set(key, value);
                }
            }
        }
        
        const options = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        };
        
        if (method === 'POST' && data) {
            const formData = new FormData();
            for (const [key, value] of Object.entries(data)) {
                formData.append(key, value);
            }
            options.body = formData;
        }
        
        fetch(url, options);
        
    }
    
    /**
     * Affiche/masque le loading
     */
    showLoading(show) {
        $('.cms_watchman_content_loading').toggleClass('hidden', !show);
    }
    
    /**
     * Affiche/masque l'état vide
     */
    showEmptyState(show) {
        $('.cms_watchman_content_empty').toggleClass('hidden', !show);
    }
    
    /**
     * Affiche/masque le contenu principal
     */
    showMainContent(show) {
        $('.cms_watchman_content_main_body').toggleClass('hidden', !show);
    }
    
    /**
     * Affiche une notification
     */
    // showNotification(message, type = 'info') {
    //     // Utilisation du système de notification de GLPI si disponible
    //     if (window.glpi_toast_info) {
    //         switch (type) {
    //             case 'success':
    //                 window.glpi_toast_info(message);
    //                 break;
    //             case 'error':
    //                 window.glpi_toast_error(message);
    //                 break;
    //             case 'warning':
    //                 window.glpi_toast_warning(message);
    //                 break;
    //             default:
    //                 window.glpi_toast_info(message);
    //         }
    //     } else {
    //         // Fallback avec alert
    //         alert(message);
    //     }
    // }
}

/**
 * Fonction utilitaire pour debounce
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Initialisation quand le DOM est prêt
 */
$(document).ready(function() {
    // Récupération du token CSRF depuis le template
    window.csrf_token = $('input[name="_glpi_csrf_token"]').val() || '';
    
    // Initialisation du gestionnaire d'alertes
    window.watchmanAlertsManager = new WatchmanAlertsManager();
    
    console.log('Watchman Alerts Manager initialisé');
});