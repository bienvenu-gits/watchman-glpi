<?php

use GlpiPlugin\Watchman\WatchmanProfile;

include ('../../../inc/includes.php');

// Vérifier les droits de configuration
WatchmanProfile::checkRightOr403(WatchmanProfile::RIGHT_WATCHMAN_CONFIG, WatchmanProfile::UPDATE);

// Traitement du formulaire
if (isset($_POST["update_rights"])) {
    $profiles_id = intval($_POST['profiles_id']);
    
    if ($profiles_id > 0) {
        $rights = WatchmanProfile::getAllRights();
        
        foreach ($rights as $rightname => $config) {
            $new_value = 0;
            
            if (isset($_POST[$rightname]) && is_array($_POST[$rightname])) {
                foreach ($_POST[$rightname] as $right_val) {
                    $new_value += intval($right_val);
                }
            }
            
            // Mettre à jour le droit
            $profileRight = new ProfileRight();
            $profileRight->updateProfileRights($profiles_id, [$rightname => $new_value]);
        }
        
        Session::addMessageAfterRedirect(__('Droits mis à jour avec succès', 'watchman'), false, INFO);
    }
    
    Html::back();
}

// Affichage de la page
Html::header(
   __('Gestion des profils Watchman', 'watchman'),
   $_SERVER['PHP_SELF'],
   "plugins",
   WatchmanProfile::class,
   "profile"
);

?>

<div class="spaced">
    <h2><?= __('Gestion des droits Watchman', 'watchman') ?></h2>
    
    <div class="center">
        <form method="post" action="<?= $_SERVER['PHP_SELF'] ?>">
            <table class="tab_cadre_fixe">
                <tr>
                    <th colspan="2"><?= __('Sélectionner un profil', 'watchman') ?></th>
                </tr>
                <tr class="tab_bg_1">
                    <td><?= __('Profil', 'watchman') ?></td>
                    <td>
                        <?php
                        $profiles = [];
                        $profile = new Profile();
                        foreach ($profile->find() as $p) {
                            $profiles[$p['id']] = $p['name'];
                        }
                        
                        $selected_profile = $_GET['profiles_id'] ?? 0;
                        Dropdown::showFromArray('profiles_id', $profiles, [
                            'value' => $selected_profile,
                            'on_change' => 'window.location.href="' . $_SERVER['PHP_SELF'] . '?profiles_id=" + this.value'
                        ]);
                        ?>
                    </td>
                </tr>
            </table>
        </form>
    </div>
    
    <?php if ($selected_profile > 0): ?>
        <div class="spaced">
            <form method="post" action="<?= $_SERVER['PHP_SELF'] ?>">
                <input type="hidden" name="profiles_id" value="<?= $selected_profile ?>">
                <input type="hidden" name="_glpi_csrf_token" value="<?= Session::getNewCSRFToken() ?>">
                
                <?= WatchmanProfile::displayRightsForProfile($selected_profile) ?>
                
                <div class="center" style="margin-top: 15px;">
                    <button type="submit" name="update_rights" class="submit">
                        <?= __('Mettre à jour les droits', 'watchman') ?>
                    </button>
                </div>
            </form>
        </div>
        
        <div class="spaced">
            <h3><?= __('Profils ayant accès à Watchman', 'watchman') ?></h3>
            
            <table class="tab_cadre_fixe">
                <tr>
                    <th><?= __('Profil', 'watchman') ?></th>
                    <th><?= __('Voir alertes', 'watchman') ?></th>
                    <th><?= __('Gérer alertes', 'watchman') ?></th>
                    <th><?= __('Administration', 'watchman') ?></th>
                    <th><?= __('Configuration', 'watchman') ?></th>
                    <th><?= __('Tâches CRON', 'watchman') ?></th>
                </tr>
                
                <?php
                $profiles_with_access = WatchmanProfile::getProfilesWithAccess();
                foreach ($profiles_with_access as $profile_id => $profile_info):
                ?>
                <tr class="tab_bg_1">
                    <td>
                        <a href="<?= $_SERVER['PHP_SELF'] ?>?profiles_id=<?= $profile_id ?>">
                            <?= $profile_info['name'] ?>
                        </a>
                    </td>
                    <td class="center">
                        <?= isset($profile_info['rights'][WatchmanProfile::RIGHT_WATCHMAN_VIEW]) && 
                            $profile_info['rights'][WatchmanProfile::RIGHT_WATCHMAN_VIEW] > 0 ? '✓' : '✗' ?>
                    </td>
                    <td class="center">
                        <?= isset($profile_info['rights'][WatchmanProfile::RIGHT_WATCHMAN_MANAGE]) && 
                            $profile_info['rights'][WatchmanProfile::RIGHT_WATCHMAN_MANAGE] > 0 ? '✓' : '✗' ?>
                    </td>
                    <td class="center">
                        <?= isset($profile_info['rights'][WatchmanProfile::RIGHT_WATCHMAN_ADMIN]) && 
                            $profile_info['rights'][WatchmanProfile::RIGHT_WATCHMAN_ADMIN] > 0 ? '✓' : '✗' ?>
                    </td>
                    <td class="center">
                        <?= isset($profile_info['rights'][WatchmanProfile::RIGHT_WATCHMAN_CONFIG]) && 
                            $profile_info['rights'][WatchmanProfile::RIGHT_WATCHMAN_CONFIG] > 0 ? '✓' : '✗' ?>
                    </td>
                    <td class="center">
                        <?= isset($profile_info['rights'][WatchmanProfile::RIGHT_WATCHMAN_CRON]) && 
                            $profile_info['rights'][WatchmanProfile::RIGHT_WATCHMAN_CRON] > 0 ? '✓' : '✗' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
    
    <div class="spaced">
        <h3><?= __('Informations sur les droits', 'watchman') ?></h3>
        
        <table class="tab_cadre_fixe">
            <tr>
                <th><?= __('Droit', 'watchman') ?></th>
                <th><?= __('Description', 'watchman') ?></th>
            </tr>
            
            <?php
            $all_rights = WatchmanProfile::getAllRights();
            foreach ($all_rights as $rightname => $config):
            ?>
            <tr class="tab_bg_1">
                <td><strong><?= $config['label'] ?></strong></td>
                <td><?= $config['description'] ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<?php
Html::footer();