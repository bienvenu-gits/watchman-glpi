/**
 * Gestionnaire JavaScript pour la page de détail d'alerte Watchman
 */

class WatchmanAlertDetailManager {
    constructor() {
        this.alertId = null;
        this.currentAlert = null;
        this.isLoading = false;
        this.ajaxUrl = window.WATCHMAN_CONFIG?.ajaxUrl || './ajax/alerts.php';
        this.csrfToken = window.WATCHMAN_CONFIG?.csrfToken || '';
        
        this.init();
    }
    
    /**
     * Initialisation du gestionnaire
     */
    init() {
        console.log('token',this.csrfToken);
        // Récupération de l'ID de l'alerte depuis l'URL
        this.alertId = this.getAlertIdFromUrl();
        
        if (!this.alertId) {
            this.showError('ID d\'alerte manquant dans l\'URL');
            return;
        }
        
        this.bindEvents();
        this.loadAlertDetail();
    }
    
    /**
     * Récupère l'ID de l'alerte depuis l'URL
     */
    getAlertIdFromUrl() {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('alert_id') || urlParams.get('id');
    }
    
    /**
     * Liaison des événements
     */
    bindEvents() {
        // Bouton de patch
        $('#cms_watchman_alert_patch_btn').on('click', () => {
            this.togglePatchStatus();
        });
        
        // Bouton de retour
        $('.cms_watchman_alert_detail_title a').on('click', (e) => {
            e.preventDefault();
            this.goBack();
        });
    }
    
    /**
     * Charge les détails de l'alerte
     */
    async loadAlertDetail() {
        if (this.isLoading) return;
        
        this.isLoading = true;
        this.showLoading(true);
        
        try {
            const response = await this.makeRequest('get_alert_detail', `alert_id=${this.alertId}`);
            
            if (response.success) {
                this.currentAlert = response.data;
                this.displayAlertDetail(response.data);
                this.showMainContent(true);
                this.showEmptyState(false);
            } else {
                throw new Error(response.error || 'Erreur inconnue');
            }
            
        } catch (error) {
            console.error('Erreur lors du chargement des détails:', error);
            this.showError('Erreur lors du chargement des détails de l\'alerte');
            this.showEmptyState(true);
            this.showMainContent(false);
        } finally {
            this.isLoading = false;
            this.showLoading(false);
        }
    }
    
    /**
     * Affiche les détails de l'alerte
     */
    displayAlertDetail(alert) {
        // Informations principales
        $('#cms_watchman_alert_cve_id').text(alert.cve_id || 'N/A');
        $('#cms_watchman_alert_description').text(alert.description || 'Aucune description disponible');
        $('#cms_watchman_alert_cve_assigner').text(alert.cve_assigner);
        $('#cms_watchman_alert_score').text(alert.score);
        $('#cms_watchman_alert_severity_value').text(alert.severity);
        $('#cms_watchman_alert_attack_vector').text(`Vecteur d'attaque: ${alert.attack_vector}`);
        
        // Dates
        $('#cms_watchman_alert_published').text(alert.published_at || 'N/A');
        $('#cms_watchman_alert_updated').text(alert.modified_at || 'N/A');
        
        // Mise à jour du bouton de patch
        this.updatePatchButton(alert.patched);
        this.updateTicketButton();
        
        // Barres de progression
        this.updateProgressBars(alert.progress_levels);
        
        // Tableaux
        this.populateReferencesTable(alert.references);
        this.populateProductsTable(alert.products);
        this.populateVulnerableStackTable(alert.stack);
    }
    
    /**
     * Met à jour le bouton de patch
     */
    updatePatchButton(isPatched) {
        const button = $('#cms_watchman_alert_patch_btn');
        const icon = button.find('i');
        const text = button.find('span');
        
        if (isPatched) {
            // button.removeClass('cms_watchman_btn-light').addClass('cms_watchman_btn-warning');
            // icon.removeClass('mdi-update').addClass('mdi-close');
            // text.text('Annuler le patch');
            button.attr('class', 'hidden');
        } else {
            button.removeClass('cms_watchman_btn-warning').addClass('cms_watchman_btn-light');
            icon.removeClass('mdi-close').addClass('mdi-update');
            text.text('Patch');
            button.attr('title', 'Marquer comme corrigée');
        }
    }

    updateTicketButton() {
        const button = $('#cms_watchman_alert_ticket_btn');
        
        button.click(()=>{
            window.open(this.currentAlert.ticket_url, '_blank');
        })
    }
    
    /**
     * Met à jour les barres de progression
     */
    updateProgressBars(progressLevels) {
        if (!progressLevels) return;
        
        // Sévérité
        this.updateProgressBar('cms_watchman_alert_severity_progressbar', 
                             progressLevels.severity.level, 
                             progressLevels.severity.label,
                             this.getSeverityColor(progressLevels.severity.level));
        
        // Complexité d'attaque
        this.updateProgressBar('cms_watchman_alert_complexity_attack_progressbar', 
                             progressLevels.attack_complexity.level, 
                             progressLevels.attack_complexity.label);
        
        // Privilèges requis
        this.updateProgressBar('cms_watchman_alert_privileges_required_progressbar', 
                             progressLevels.privileges_required.level, 
                             progressLevels.privileges_required.label);
        
        // Interaction utilisateur
        this.updateProgressBar('cms_watchman_alert_user_interaction_progressbar', 
                             progressLevels.user_interaction.level, 
                             progressLevels.user_interaction.label);
        
        // Impact confidentialité
        this.updateProgressBar('cms_watchman_alert_confidentiality_impact_progressbar', 
                             progressLevels.confidentiality_impact.level, 
                             progressLevels.confidentiality_impact.label);
        
        // Impact intégrité
        this.updateProgressBar('cms_watchman_alert_integrity_impact_progressbar', 
                             progressLevels.integrity_impact.level, 
                             progressLevels.integrity_impact.label);
        
        // Impact disponibilité
        this.updateProgressBar('cms_watchman_alert_availability_impact_progressbar', 
                             progressLevels.availability_impact.level, 
                             progressLevels.availability_impact.label);
    }
    
    /**
     * Met à jour une barre de progression individuelle
     */
    updateProgressBar(elementId, level, label, color = '#007bff') {
        const element = $(`#${elementId}`);
        const valueSpan = element.find('.value');
        const fillDiv = element.find('.cms_watchman_progressbar_fill');
        
        valueSpan.text(label);
        fillDiv.css({
            'width': `${level}%`,
            'background-color': color
        });
    }
    
    /**
     * Obtient la couleur selon le niveau de sévérité
     */
    getSeverityColor(level) {
        if (level >= 90) return '#dc3545'; // Rouge - Critique
        if (level >= 75) return '#fd7e14'; // Orange - Élevé
        if (level >= 50) return '#ffc107'; // Jaune - Moyen
        if (level >= 25) return '#28a745'; // Vert - Faible
        return '#6c757d'; // Gris - N/A
    }
    
    /**
     * Remplit le tableau des références
     */
    populateReferencesTable(references) {
        const tbody = $('#cms_watchman_alert_references_table tbody');
        tbody.empty();
        
        if (!references || references.length === 0) {
            tbody.append(`
                <tr>
                    <td colspan="2" class="text-center">Aucune référence disponible</td>
                </tr>
            `);
            return;
        }
        
        references.forEach(ref => {
            const row = `
                <tr>
                    <td>${this.escapeHtml(ref.name)}</td>
                    <td>
                        <a href="${this.escapeHtml(ref.url)}" target="_blank" rel="noopener noreferrer">
                            ${this.escapeHtml(ref.url)}
                            <i class="fa fa-external-link-alt"></i>
                        </a>
                    </td>
                </tr>
            `;
            tbody.append(row);
        });
    }
    
    /**
     * Remplit le tableau des produits
     */
    populateProductsTable(products) {
        const tbody = $('#cms_watchman_alert_product_table tbody');
        tbody.empty();
        
        if (!products || products.length === 0) {
            tbody.append(`
                <tr>
                    <td colspan="3" class="text-center">Aucun produit vulnérable identifié</td>
                </tr>
            `);
            return;
        }
        
        products.forEach(product => {
            const row = `
                <tr>
                    <td>${this.escapeHtml(product.vendor)}</td>
                    <td>${this.escapeHtml(product.name)}</td>
                    <td>${this.escapeHtml(product.version)}</td>
                </tr>
            `;
            tbody.append(row);
        });
    }
    
    /**
     * Remplit le tableau des stacks vulnérables
     */
    populateVulnerableStackTable(stack) {
        const tbody = $('#cms_watchman_alert_vulnerable_stack tbody');
        tbody.empty();
        
        if (!stack) {
            tbody.append(`
                <tr>
                    <td colspan="2" class="text-center">Aucune stack vulnérable identifiée</td>
                </tr>
            `);
            return;
        }
        
        const row = `
            <tr>
                <td>${this.escapeHtml(stack.name)}</td>
                <td>${this.escapeHtml(stack.version)}</td>
            </tr>
        `;
        tbody.append(row);
    }
    
    /**
     * Bascule le statut de patch de l'alerte
     */
    async togglePatchStatus() {
        if (!this.currentAlert) return;
        
        const newStatus = !this.currentAlert.patched;
        const confirmMessage = newStatus 
            ? 'Voulez-vous marquer cette alerte comme corrigée ?' 
            : 'Voulez-vous annuler le statut de correction de cette alerte ?';
            
        if (!confirm(confirmMessage)) {
            return;
        }
        
        try {
            const button = $('#cms_watchman_alert_patch_btn');
            const originalHtml = button.html();
            
            // Animation de chargement
            button.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> <span>Traitement...</span>');
            
            const response = await this.makeAjaxRequest('patch_alert', {
                alert_id: this.alertId,
                patched: newStatus ? 1 : 0,
                _glpi_csrf_token: this.csrfToken
            },this.ajaxUrl);
            
            if (response.success) {
                this.currentAlert.patched = newStatus;
                this.updatePatchButton(newStatus);
                this.showNotification(response.message, 'success');
            } else {
                throw new Error(response.error || 'Erreur inconnue');
            }
            
        } catch (error) {
            console.error('Erreur lors de la mise à jour:', error);
            this.showNotification('Erreur lors de la mise à jour du statut', 'error');
        } finally {
            // Restauration du bouton
            const button = $('#cms_watchman_alert_patch_btn');
            button.prop('disabled', false);
            this.updatePatchButton(this.currentAlert.patched);
        }
    }
    
    /**
     * Retour à la page précédente
     */
    goBack() {
        if (window.history.length > 1) {
            window.history.back();
        } else {
            // Redirection vers la page des alertes si pas d'historique
            window.location.href = window.location.pathname.replace(/\/[^\/]*$/, '') + '?page=cms_watchman_alert';
        }
    }
    
    /**
     * Effectue une requête AJAX
     */
    async makeRequest(action, queryParams = '', method = 'GET', data = null) {
        const url = queryParams ? `${this.ajaxUrl}?action=${action}&${queryParams}` : `${this.ajaxUrl}?action=${action}`;
        
        const options = {
            method: method,
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'Referer': window.location.href
            }
        };
        
        if (method === 'POST' && data) {
            options.body = new URLSearchParams(data).toString();
        }
        console.log('response',url)
        
        const response = await fetch(url, options);
        
        if (!response.ok) {
            throw new Error(`Erreur HTTP: ${response.status}`);
        }
        
        
        return await response.json();
    }

    async makeAjaxRequest(action, data = {},url) {
    const payload = {
        action: action,
        _glpi_csrf_token: WATCHMAN_CONFIG.csrfToken,
        ...data
    };

    
    try {
        const response = await fetch(`${url}?action=${action}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        });

        
        const result = await response.json();
        console.log('response',result);
        
        if (!response.ok) {
            throw new Error(result.message || 'Erreur serveur');
        }
        
        return result;
    } catch (error) {
        console.error('Erreur AJAX:', error);
        throw error;
    }
}
    
    /**
     * Échappe les caractères HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Affiche/masque l'indicateur de chargement
     */
    showLoading(show) {
        const loadingElement = $('.cms_watchman_content_loading');
        if (show) {
            loadingElement.removeClass('hidden').show();
        } else {
            loadingElement.addClass('hidden').hide();
        }
    }
    
    /**
     * Affiche/masque le contenu principal
     */
    showMainContent(show) {
        const mainContent = $('.cms_watchman_content_main_body');
        if (show) {
            mainContent.removeClass('hidden').show();
        } else {
            mainContent.addClass('hidden').hide();
        }
    }
    
    /**
     * Affiche/masque l'état vide
     */
    showEmptyState(show) {
        const emptyElement = $('.cms_watchman_content_empty');
        if (show) {
            emptyElement.removeClass('hidden').show();
        } else {
            emptyElement.addClass('hidden').hide();
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
     * Affiche une erreur
     */
    showError(message) {
        console.error('Erreur Watchman:', message);
        this.showNotification(message, 'error');
    }
}

// Initialisation automatique quand le DOM est prêt
$(document).ready(function() {
    if (window.location.search.includes('page=cms_watchman_alert_detail') || 
        window.location.search.includes('alert_id=')) {
        window.watchmanAlertDetail = new WatchmanAlertDetailManager();
    }
});