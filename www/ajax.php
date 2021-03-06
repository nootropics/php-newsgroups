<?php
require_once('inc/Newsgroup.php');
require_once('inc/account.php');
require_once('inc/permissions.php');

$user = Login::GetLoggedInUser();
$access = Login::GetEffectiveAccessControl();

if (isset($_POST['id']) && !empty($_POST['id'])) {
    try {
        $post = new Post($_POST['id']);
        if ($access->canReadGroup($post->getGroup())) {
            if ($user && $_POST['mark_read'] == "1") {
                $user->setRead($post, true);
            }
            send_ajax_post($post);
        } else {
            send_ajax_failure();
        }
    } catch (PostDoesNotExistException $e) {
        send_ajax_failure();
    }
}

if (isset($_POST['get_posts_after']) && !empty($_POST['get_posts_after'])) {
    try {
        $group = new Newsgroup($_POST['newsgroup']);
        $last_update = (int)$_POST['get_posts_after'];
        $posts = $group->getNewPostsSince($last_update);
        send_ajax_post_list($posts);
    } catch (GroupDoesNotExistException $e) {
        send_ajax_failure();
    }
}

if (isset($_POST['get_cancellations_after']) && !empty($_POST['get_cancellations_after'])) {
    try {
        $group = new Newsgroup($_POST['newsgroup']);
        $last_cancellation = (int)$_POST['get_cancellations_after'];
        $cancellations = $group->getCancellationsSince($last_cancellation);
        send_ajax_cancellation_list($cancellations);
    } catch (GroupDoesNotExistException $e) {
        send_ajax_failure();
    }
}

if ($user && isset($_POST['mark_unread_id']) && !empty($_POST['mark_unread_id'])) {
    try {
        $post = new Post($_POST['mark_unread_id']);
        $user->setRead($post, false);
        send_ajax_success();
    } catch (PostDoesNotExistException $e) {
        send_ajax_failure();
    }
}

if ($user && isset($_POST['delete_post_id']) && !empty($_POST['delete_post_id'])) {
    try {
        $post = new Post($_POST['delete_post_id']);
        /* A user can only delete a post tree if they wrote all of the posts in
            the tree or they are an administrator. */
        if ($user->isAdmin() || $post->treeWrittenBy($user)) {
            $post->recursiveDelete();
            send_ajax_success();
        } else {
            send_ajax_failure();
        }
    } catch (PostDoesNotExistException $e) {
        send_ajax_failure();
    }
}

function send_ajax_post($post)
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $xml .= "<response>";
    $xml .= "<status>success</status>";
    $xml .= get_post_xml($post, true);
    $xml .= "</response>";
    send_xml_response($xml);
}

function send_ajax_post_list($posts)
{
    $safe_time = htmlentities(time(), ENT_QUOTES);
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $xml .= "<response>";
    $xml .= "<status>success</status>";
    $xml .= "<currenttime>$safe_time</currenttime>";
    foreach ($posts as $post) {
        $xml .= get_post_xml($post, false);
    }
    $xml .= "</response>";
    send_xml_response($xml);
}

function send_ajax_cancellation_list($cancellations) {
    $currentid = Newsgroup::GetLastCancellationId();
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $xml .= "<response>";
    $xml .= "<status>success</status>";
    $xml .= "<currentid>" . htmlentities($currentid, ENT_QUOTES) . "</currentid>";
    foreach ($cancellations as $cancel) {
        $xml .= "<cancel>" . htmlentities($cancel, ENT_QUOTES) . "</cancel>";
    }
    $xml .= "</response>";
    send_xml_response($xml);
}

function get_post_xml($post, $include_contents)
{
    $safe_id = htmlentities($post->getID(), ENT_QUOTES);
    $safe_user = htmlentities($post->getUser(), ENT_QUOTES);
    $safe_time = htmlentities($post->getTime(), ENT_QUOTES);
    $safe_title = htmlentities($post->getTitle(), ENT_QUOTES);
    $safe_formatted_time = htmlentities($post->getFormattedTime(), ENT_QUOTES);
    $parent = $post->getParent();
    if ($parent->isRootLevel()) {
        $safe_parent_id = "";
    } else {
        $safe_parent_id = htmlentities($parent->getID(), ENT_QUOTES);
    }
    if ($include_contents) {
        $safe_contents = htmlentities($post->getContentsHtml(), ENT_QUOTES);
    } else {
        $safe_contents = "";
    }
    // TODO: the previous post id
    $xml = <<<EOT
    <post>
        <id>$safe_id</id>
        <parent>$safe_parent_id</parent>
        <user>$safe_user</user>
        <time>$safe_time</time>
        <formattedtime>$safe_formatted_time</formattedtime>
        <title>$safe_title</title>
        <contents>$safe_contents</contents>
    </post>
EOT;
    return $xml;
}

function send_ajax_failure()
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $xml .= "<response>";
    $xml .= "<status>fail</status>";
    $xml .= "</response>";
    send_xml_response($xml);
}

function send_ajax_success()
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $xml .= "<response>";
    $xml .= "<status>success</status>";
    $xml .= "</response>";
    send_xml_response($xml);
}

function send_xml_response($xml)
{
    header('Content-Type: text/xml');
    echo $xml;
    die();
}

?>
