<?php
require_once('ui/view.php');
require_once('inc/account.php');
class HeaderView extends View
{
    public function show()
    {
        $current_user = Login::GetLoggedInUser();
        if ($current_user === FALSE) {
            ?>
                <a href="index.php">Home</a> |
                <a href="login.php">Log In</a> | 
                <a href="register.php">Register</a>
            <?
            } else {
            ?>
                You are logged in as <?php echo $current_user->getUsername(); ?>.
                <a href="index.php">Home</a> |
                <a href="logout.php">Log out</a> |
                <a href="settings.php">Settings</a>
                <?php if ($current_user->isAdmin()) { ?>
                    | <a href="admin.php">Administration</a>
                <? } ?>
            <?
        }
    }
}
?>
