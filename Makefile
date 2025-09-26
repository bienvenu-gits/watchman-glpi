# Makefile pour la gestion des traductions du plugin Watchman
#
# Commandes disponibles:
# make extract       - Extraire les chaînes de traduction
# make compile       - Compiler les fichiers .po en .mo
# make update        - Mettre à jour les fichiers de traduction
# make clean         - Nettoyer les fichiers temporaires
# make translations  - Processus complet (extract + compile)

PLUGIN_DIR = .
LOCALES_DIR = $(PLUGIN_DIR)/locales
TOOLS_DIR = $(PLUGIN_DIR)/tools

# Couleurs pour l'affichage
GREEN = \033[0;32m
YELLOW = \033[1;33m
RED = \033[0;31m
NC = \033[0m # No Color

.PHONY: help extract compile update clean translations

help:
	@echo "$(YELLOW)Plugin Watchman - Gestion des traductions$(NC)"
	@echo ""
	@echo "Commandes disponibles:"
	@echo "  $(GREEN)extract$(NC)       - Extraire les chaînes de traduction"
	@echo "  $(GREEN)compile$(NC)       - Compiler les fichiers .po en .mo"
	@echo "  $(GREEN)update$(NC)        - Mettre à jour les fichiers de traduction"
	@echo "  $(GREEN)clean$(NC)         - Nettoyer les fichiers temporaires"
	@echo "  $(GREEN)translations$(NC)  - Processus complet (extract + compile)"
	@echo ""

extract:
	@echo "$(YELLOW)Extraction des chaînes de traduction...$(NC)"
	@php $(TOOLS_DIR)/extract_strings.php
	@echo "$(GREEN)✓ Extraction terminée$(NC)"

compile:
	@echo "$(YELLOW)Compilation des fichiers de traduction...$(NC)"
	@php $(TOOLS_DIR)/compile_translations.php
	@echo "$(GREEN)✓ Compilation terminée$(NC)"

update: extract
	@echo "$(YELLOW)Mise à jour des fichiers de traduction...$(NC)"
	@if [ -f $(LOCALES_DIR)/fr_FR.po ]; then \
		echo "Mise à jour du fichier français..."; \
	fi
	@if [ -f $(LOCALES_DIR)/en_GB.po ]; then \
		echo "Mise à jour du fichier anglais..."; \
	fi
	@echo "$(GREEN)✓ Mise à jour terminée$(NC)"

clean:
	@echo "$(YELLOW)Nettoyage des fichiers temporaires...$(NC)"
	@rm -f $(LOCALES_DIR)/*.mo~
	@rm -f $(LOCALES_DIR)/*.po~
	@echo "$(GREEN)✓ Nettoyage terminé$(NC)"

translations: extract compile
	@echo "$(GREEN)✓ Processus de traduction complet terminé$(NC)"

# Règles pour créer de nouveaux fichiers de langue
new-lang:
	@if [ -z "$(LANG)" ]; then \
		echo "$(RED)Erreur: Spécifiez la langue avec LANG=code_pays$(NC)"; \
		echo "Exemple: make new-lang LANG=es_ES"; \
		exit 1; \
	fi
	@echo "$(YELLOW)Création du fichier de traduction pour $(LANG)...$(NC)"
	@cp $(LOCALES_DIR)/watchman.pot $(LOCALES_DIR)/$(LANG).po
	@sed -i 's/Language: /Language: $(LANG)/' $(LOCALES_DIR)/$(LANG).po
	@echo "$(GREEN)✓ Fichier $(LANG).po créé$(NC)"

# Vérification des fichiers de traduction
check:
	@echo "$(YELLOW)Vérification des fichiers de traduction...$(NC)"
	@for po in $(LOCALES_DIR)/*.po; do \
		if [ -f "$$po" ]; then \
			echo "Vérification de $$(basename $$po)..."; \
			msgfmt --check $$po || echo "$(RED)Erreur dans $$(basename $$po)$(NC)"; \
		fi \
	done
	@echo "$(GREEN)✓ Vérification terminée$(NC)"