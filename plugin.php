<?php
/**
 * Plugin Name: Customer Lobby Verified Reviews
 * Description: A custom plugin created to grab reviews from RSS
 * Author: Customer Lobby | www.customerlobby.com
 * Version: 4.1
 */ 

define('CL_PATH', dirname(__FILE__));
define('CL_URL', plugins_url(basename(dirname(__FILE__))));
define('CL_PREF', "customer_lobby_");

// Register a customer lobby widget
class Customer_Lobby_Widget extends WP_Widget
{
    public function __construct()
    {
        $params = array(
            'name' => "Customer Lobby",
            'description' => "Shows the latest reviews from RSS",
        );

        parent::__construct("customer_lobby_widget", '', $params);

        add_action('wp_head', array($this, 'attach_headers'));
    }

    public function attach_headers()
    {
        echo '<link rel="stylesheet" href="' . CL_URL . '/css/customer-lobby.css" />';
    }

    public function form($instance)
    {
        ?>

<p>
	<label for="<?php echo $this->get_field_id(CL_PREF . 'title'); ?>">Title:</label>
	<input type="text" class="widefat"
		id="<?php echo $this->get_field_id(CL_PREF . 'title'); ?>"
		name="<?php echo $this->get_field_name(CL_PREF . 'title'); ?>"
		value="<?php if (isset($instance[CL_PREF . 'title'] )) { echo esc_attr($instance[CL_PREF . 'title']); } ?>" />
</p>

<p>
	<label for="<?php echo $this->get_field_id(CL_PREF . 'rss_url'); ?>">RSS
		URL:</label> <input type="text" class="widefat"
		id="<?php echo $this->get_field_id(CL_PREF . 'rss_url'); ?>"
		name="<?php echo $this->get_field_name(CL_PREF . 'rss_url'); ?>"
		value="<?php if (isset($instance[CL_PREF . 'rss_url'])) { echo esc_attr($instance[CL_PREF . 'rss_url']); } ?>" />
<p class="description">The url should contain http://</p>
</p>

<p>
	<label for="<?php echo $this->get_field_id(CL_PREF . 'ammount'); ?>">How
		many reviews?:</label> <input type="number" class="widefat"
		id="<?php echo $this->get_field_id(CL_PREF . 'ammount'); ?>"
		name="<?php echo $this->get_field_name(CL_PREF . 'ammount'); ?>"
		value="<?php if (isset($instance[CL_PREF . 'ammount'])) { echo esc_attr($instance[CL_PREF . 'ammount']); } ?>" />
</p>

<p>
	<label for="<?php echo $this->get_field_id(CL_PREF . 'cache'); ?>">Cache
		for?:</label> <input placeholder='default: 1hr' type="number"
		class="widefat" id="<?php echo $this->get_field_id(CL_PREF . 'cache'); ?>"
		name="<?php echo $this->get_field_name(CL_PREF . 'cache'); ?>"
		value="<?php if (isset($instance[CL_PREF . 'cache'])) { echo esc_attr($instance[CL_PREF . 'cache']); } ?>" />
<p class="description">In hours. Default is 1 hr. 0 - never</p>
<?php 
if (!file_exists(CL_PATH . "/cached") || !is_writable(CL_PATH . "/cached")) {
    echo "<span style='color:red'>Cache folder does not exist or is not writable! Cache will be disabled!</span>";
}
?>
</p>

<?php
    }

    public function widget($args, $instance)
    {
        extract($instance);
        extract($args);

        $data = $this->get_reviews($instance);

        // get template        
        $template_file = file_get_contents(CL_PATH . '/loop-template.html');

        $content = "";

        if(count($data['reviews']) == 0){
          $content = "";
        }else{
          foreach ($data['reviews'] as $review) {

              $template = $template_file;

              $template = str_replace('$title', $this->format_string($review['title'], 20), $template);
              $template = str_replace('$reviewer', $review['review_by'], $template);
              $template = str_replace('$date', date('m/d/Y', strtotime($review['date'])), $template);
              $template = str_replace('$summary', $this->format_string($review['review'], 60), $template);
              $template = str_replace('$rating', $review['rating'], $template);
              $template = str_replace('$url', $review['url'], $template);

              $content .= $template;
          }
          
        }

        echo $before_widget;
        echo $before_title . $instance[CL_PREF . 'title'] . $after_title;
        echo "
<div id='cust_lobby_widget'>
    <div class='hreview-aggregate'>
        <span class='item'><span class='photo'>&nbsp;</span>
        <span class='fn'>{$data['title']}</span></span>
        <p class='hreview-count'><span class='count'>{$data['count']}</span> " . __('customer reviews') . "</p>
        <p class='hreview-average left'>" . __('Average rating:') . "<span class='average star-rating rating'>{$data['average']}</span></p>
        <div class='star-rating rating-{$data['average']} left stars-align' style='margin-top:5px;'>&nbsp;</div>
        <div class='clear'></div>
    </div>
    {$content}
    <a class='cust_lobby_more' href='{$data['url']}' target='_blank'>More Verified Reviews</a>
    <div id='cust_lobby_footer'>
      <table border='0'>
        <tr>
          <td><span class='verified'>Verified by</span></td>
          <td><h4>Customer Lobby</h4></td>
        </tr>
      </table>
    </div>
</div>";
        echo $after_widget;
    }

    public function get_reviews($values)
    {
        $url = $values[CL_PREF . "rss_url"];
        $max = $values[CL_PREF . "ammount"];
        $hours = intval($values[CL_PREF . "cache"]);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return; // not a valid url
        }

        if (!function_exists('fetch_feed')) {
            return; // no simple pie why bother.
        }

        $cachefile = CL_PATH . "/cached/reviews.serialized";
        $cachetime = $hours * 60 * 60; // hours

        // if file is young but not older than the cache time
        if (@filemtime(utf8_decode($cachefile)) < (time() - $cachetime) || empty($hours)) {
            $this->fetch_feed($url, $max);
        }

        // get cached

        $data = @file_get_contents(CL_PATH . "/cached/reviews.serialized");
        return unserialize($data);          
    }

    public function fetch_feed($url, $max)
    {
        $return = array();
        $feed = fetch_feed($url);

        if (!is_wp_error($feed)) { // no error
            $maxitems = $feed->get_item_quantity($max);
            $rss_items = $feed->get_items(0, $maxitems);            
        }

        if ($maxitems == 0) {
            return "No items";
        }

        $title = $feed->get_title();
        $desc = $feed->get_description();
        $url = $feed->get_permalink();
        

        preg_match('/\d+ Published Reviews/', $desc, $matches);
        $count = intval($matches[0]);

        preg_match('/\d+ Average Rating/', $desc, $matches);
        $average = intval($matches[0]);

        foreach ($rss_items as $item) {
            $content = $item->get_content();
            $author =  $item->get_author();
            $author_name = $author->email;


            if (preg_match('/\d/Uis', $item->get_title(), $matches)) {
                $rating = $matches[0];
            }
            

            $content_array = explode(" - ", $content);
            $author_from_content = array_pop($content_array);
            $content = implode("-",$content_array);

            // if (preg_match('/\-\s.*\./Uis', $content, $matches)) {
            //     $content = rtrim($content, $matches[0]);
            //     $reviewby = ltrim(end($matches), '- By');
            //     $reviewby = rtrim($reviewby, '.');
            // }
            
            
            $return[] = array(
                'title' => str_replace($rating . ' Star Review: ', '', $item->get_title()),
                'date' => $item->get_date('d-m-Y H:i:s'),
                'url' => $item->get_permalink(),
                'review' => trim($content, ". "),
                'rating' => $rating,
                'review_by' => (empty($author_name)? $author_from_content : $author_name)
            );
        }

        $reviews = array(
            'title' => $title,
            'url' => $url,
            'count' => $count,
            'average' => $average,
            'reviews' => array(),
        );

        $i = 0;
        while ($i < $maxitems) {
            $reviews['reviews'][$i] = $return[$i];
            $i++;
        }

        $cache_text = serialize($reviews);
        $fh = fopen(CL_PATH . "/cached/reviews.serialized", 'w');
        fwrite($fh, $cache_text);
        fclose($fh);

        return $reviews;
    }

    public function format_string($content, $limit = 100)
    {
        $count = 0;
        $result = "";
        $split = explode(" ", $content);

        foreach ($split as $word) {
            $temp = $result . " " . $word;
            if (strlen($temp) >= $limit) {
                break;
            } else {
                $result = $temp;
            }
        }

        return $result;
    }
}

function customer_lobby_register_widget()
{
    register_widget('Customer_Lobby_Widget');
}

add_action('widgets_init', 'customer_lobby_register_widget');
