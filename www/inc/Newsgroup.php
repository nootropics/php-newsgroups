<?php

require_once('inc/mysql.php');
require_once('inc/permissions.php');
require_once('libs/HtmlEscape.php');

define('POSTS_PER_PAGE', 50);

class GroupExistsException extends Exception { /* empty */ }
class GroupDoesNotExistException extends Exception { /* empty */ }
class PostDoesNotExistException extends Exception { /* empty */ }
class InvalidPageNumberException extends Exception { /* empty */ }

class Post
{
    private $id;
    private $group_id;
    private $user;
    private $post_date;
    private $title;
    private $contents;

    function __construct($id)
    {
        global $DB;

        $q = $DB->prepare(
            "SELECT * FROM posts WHERE id = :id"
        );
        $q->bindValue(':id', $id);
        $q->execute();
        $row = $q->fetch();
        if ($row === FALSE) {
            throw new PostDoesNotExistException("Post with id $id does not exist.");
        }

        $this->id = $id;
        $this->group_id = $row['group_id'];
        $this->user = $row['user'];
        $this->post_date = $row['post_date'];
        $this->title = $row['title'];
        $this->contents = $row['contents'];
    }

    function getGroup()
    {
        global $DB;

        $q = $DB->prepare(
            "SELECT name FROM groups WHERE id = :id"
        );
        $q->bindValue(':id', $this->group_id);
        $q->execute();
        $row = $q->fetch();
        return new Newsgroup($row['name']);
    }

    function getID()
    {
        return $this->id;
    }

    function getUser()
    {
        return $this->user;
    }

    function getTime()
    {
        return $this->post_date;
    }

    function getFormattedTime()
    {
        date_default_timezone_set("UTC");
        return date("M d, Y, H:i T", $this->getTime());
    }

    function getTitle()
    {
        return $this->title;
    }

    function getContents()
    {
        return $this->contents;
    }

    function getContentsHtml()
    {
        $html = "";
        $lines = explode("\n", $this->contents);
        $indent = 0;
        foreach ($lines as $line) {
            $leading_brackets = $this->stripLeadingBrackets($line);
            if ($indent < $leading_brackets) {
                while ($indent < $leading_brackets) {
                    $html .= '<div class="quote">';
                    $indent++;
                }
            } else if ($indent > $leading_brackets) { 
                while ($indent > $leading_brackets) {
                    $html .= "</div>";
                    $indent--;
                }
            }
            $html .= HtmlEscape::escapeText($line, false, 4) . '<br />';
        }
        return $html;
    }

    function stripLeadingBrackets(&$str)
    {
        $count = 0;
        $last_index = -1;
        for ($i = 0; $i < strlen($str); $i++) {
            if ($str[$i] !== ">" && $str[$i] !== " " && $str[$i] !== "\t") {
                break;
            }
            if ($str[$i] === ">") {
                $last_index = $i;
                $count++;
            }
        }
        $str = substr($str, $last_index + 1);
        return $count;
    }

    function getChildren($page = null)
    {
        global $DB;

        /*
         * To sort by post date, we sort by child_id, which is an auto increment
         * so it should give a reliable enough sort. However, this is very 
         * innefficient for large amounts of posts, so in the future we should
         * improve it...
         * http://www.xarg.org/2011/10/optimized-pagination-using-mysql/
         */

        $limit = "";
        if ($page !== null) {
            // Be VERY careful about SQL injection when modifying this code.
            $page = (int)$page;
            if ($page < 0) {
                throw new InvalidPageNumberException("Page number $page is not valid.");
            }
            $start = $page * POSTS_PER_PAGE;
            $duration = POSTS_PER_PAGE;
            $limit = "LIMIT $start, $duration";
        }
        $q = $DB->prepare(
            "SELECT child_id FROM replies WHERE parent_id = :parent_id ORDER BY child_id DESC $limit"
        );
        $q->bindValue(':parent_id', $this->id);
        $q->execute();
        $children = array();
        while (($row = $q->fetch()) !== FALSE) {
            $children[] = new Post($row['child_id']);
        }
        return $children;
    }

    function getParent()
    {
        global $DB;
        $q = $DB->prepare("SELECT parent_id FROM replies WHERE child_id = :child_id");
        $q->bindValue(':child_id', $this->id);
        $q->execute();
        $row = $q->fetch();
        return new Post($row['parent_id']);
    }

    function isRootLevel()
    {
        return $this->id == $this->getGroup()->getRootLevelPostID();
    }

    function treeWrittenBy($user)
    {
        // FIXME: Make this class use actual user objects, not just the
        // username. See issue #3.
        if ($user->getUsername() == $this->user) {
            $children = $this->getChildren();
            foreach ($children as $child) {
                if (!$child->treeWrittenBy($user)) {
                    return FALSE;
                }
            }
            return TRUE;
        } else {
            return FALSE;
        }
    }

    function recursiveDelete()
    {
        global $DB;

        /* Delete all of this post's children. */
        $children = $this->getChildren();
        foreach ($children as $child) {
            $child->recursiveDelete();
        }

        /* Create a cancellation for this post. */
        $q = $DB->prepare(
            "INSERT INTO cancellations (group_id, post_id) VALUES (:group_id, :post_id)"
        );
        $q->bindValue(':group_id', $this->group_id);
        $q->bindValue(':post_id', $this->id);
        $q->execute();

        /* Delete the actual post. */
        $q = $DB->prepare("DELETE FROM posts WHERE id = :id");
        $q->bindValue(':id', $this->id);
        $q->execute();

        /* Delete all of the 'readings' of this post */
        $q = $DB->prepare("DELETE FROM read_status WHERE post_id = :post_id");
        $q->bindValue(':post_id', $this->id);
        $q->execute();

        /* Delete the relationship with this post's parent. The children and
         * their relationship to this post is already gone. */
        $q = $DB->prepare("DELETE FROM replies WHERE child_id = :child_id");
        $q->bindValue(':child_id', $this->id);
        $q->execute();
    }

}

class Newsgroup
{
    private $group_id;
    private $group_name;
    private $root_post_id;

    function __construct($group_name)
    {
        global $DB;

        $q = $DB->prepare("SELECT id, root_post_id FROM groups WHERE name = :name");
        $q->bindValue(':name', $group_name);
        $q->execute();

        $result = $q->fetch();
        if ($result === FALSE) {
            throw new GroupDoesNotExistException('Group $group_name does not exist.');
        }

        $this->group_id = $result['id'];
        $this->group_name = $group_name;
        $this->root_post_id = $result['root_post_id'];
    }

    public function getID()
    {
        return $this->group_id;
    }

    public function getName()
    {
        return $this->group_name;
    }

    public function newPost($user, $title, $contents)
    {
        $this->replyPost($this->root_post_id, $user, $title, $contents);
    }

    public function replyPost($parent_id, $user, $title, $contents)
    {
        global $DB;

        /* Insert the post */
        $q = $DB->prepare(
            "INSERT INTO posts (group_id, user, post_date, title, contents)
             VALUES (:group_id, :user, :post_date, :title, :contents)"
         );
        $q->bindValue(':group_id', $this->group_id);
        $q->bindValue(':user', $user);
        $q->bindValue(':post_date', time());
        $q->bindValue(':title', $title);
        $q->bindValue(':contents', $contents);
        $q->execute();

        $new_post_id = $DB->lastInsertId();

        /* Link it to its parent */
        $q = $DB->prepare(
            "INSERT INTO replies (parent_id, child_id)
             VALUES (:parent_id, :child_id)"
        );
        $q->bindValue(':parent_id', $parent_id);
        $q->bindValue(':child_id', $new_post_id);
        $q->execute();
    }

    public function getNewPostsSince($time)
    {
        global $DB;

        $q = $DB->prepare(
            "SELECT id FROM posts 
             WHERE post_date > :post_date AND group_id = :group_id"
        );
        $q->bindValue(':post_date', $time);
        $q->bindValue(':group_id', $this->group_id);
        $q->execute();

        $new_posts = array();
        while (($row = $q->fetch()) !== FALSE) {
            $new_posts[] = new Post($row['id']);
        }
        return $new_posts;
    }

    public function getCancellationsSince($last_cancellation_id)
    {
        global $DB;

        $q = $DB->prepare(
            "SELECT post_id FROM cancellations
             WHERE id > :id AND group_id = :group_id"
        );
        $q->bindValue(':id', $last_cancellation_id);
        $q->bindValue(':group_id', $this->group_id);
        $q->execute();

        $new_cancellations = array();
        while (($row = $q->fetch()) !== FALSE) {
            $new_cancellations[] = $row['post_id'];
        }
        return $new_cancellations;
    }

    public function getTopLevelPosts($page = null)
    {
        $root = new Post($this->root_post_id);
        return $root->getChildren($page);
    }

    public function fullDelete()
    {
        global $DB;

        $root_post = new Post($this->root_post_id);
        $root_post->recursiveDelete();

        /* Remove user group access settings. */
        $q = $DB->prepare(
            "DELETE FROM group_permissions
             WHERE newsgroup_id = :newsgroup_id"
        );
        $q->bindValue(':newsgroup_id', $this->group_id);
        $q->execute();

        /* Delete the actual newsgroup. */
        $q = $DB->prepare("DELETE FROM groups WHERE id = :id");
        $q->bindValue(':id', $this->group_id);
        $q->execute();
    }

    public function getRootLevelPostID()
    {
        return $this->root_post_id;
    }

    public static function CreateGroup($group_name, $anonymous_access)
    {
        global $DB;

        if (self::GroupExists($group_name)) {
            throw new GroupExistsException("Group $group_name already exists.");
        }

        if (!UserGroup::IsValidAccessLevel($anonymous_access)) {
            throw new InvalidAccessLevelException();
        }

        /* Create the root post */
        $q = $DB->prepare(
            "INSERT INTO posts (user, post_date, title)
            VALUES (:user, :post_date, :title)"
        );
        $q->bindValue(':user', 'SYSTEM');
        $q->bindValue(':post_date', time());
        $q->bindValue(':title', "This is the root-level post for $group_name.");
        $res = $q->execute();

        /* Get the id of the just-created root post */
        $root_post_id = $DB->lastInsertId();

        /* Create the group */
        $q = $DB->prepare(
            "INSERT INTO groups (name, root_post_id, anonymous_access)
            VALUES (:name, :root_post_id, :anonymous_access)
        ");
        $q->bindValue(':name', $group_name);
        $q->bindValue(':root_post_id', $root_post_id);
        $q->bindValue(':anonymous_access', $anonymous_access);
        $q->execute();

        /* Get the id of the just-created group */
        $group_name_id = $DB->lastInsertId();

        /* Fix the root post (we didn't add the group id before). */
        $q = $DB->prepare(
            "UPDATE posts SET group_id = :group_id WHERE id = :id"
        );
        $q->bindValue(':group_id', $group_name_id);
        $q->bindValue(':id', $root_post_id);
        $q->execute();
    }

    public static function GroupExists($group_name)
    {
        global $DB;

        $q = $DB->prepare(
            "SELECT id FROM groups WHERE name = :name"
        );
        $q->bindValue(':name', $group_name);
        $q->execute();

        return $q->fetch() !== FALSE;
    }

    public static function GetAllGroups()
    {
        $names = self::GetGroupNames();
        $groups = array();
        foreach ($names as $name) {
            $groups[] = new Newsgroup($name);
        }
        return $groups;
    }

    public static function GetLastCancellationId()
    {
        global $DB;
        $q = $DB->prepare(
            "SELECT id FROM cancellations ORDER BY id DESC LIMIT 1"
        );
        $q->execute();

        $row = $q->fetch();
        if ($row === FALSE) {
            return 0;
        } else {
            return (int)$row['id'];
        }
    }

    private static function GetGroupNames()
    {
        global $DB;

        $q = $DB->prepare(
            "SELECT name FROM groups ORDER BY name ASC"
        );
        $q->execute();
        $names = array();
        while (($row = $q->fetch()) !== FALSE) {
            $names[] = $row['name'];
        }
        return $names;
    }

}

?>
