<?php

/**
 * Rich Snippet Content Plugin
 *
 * @author Brandon J. Yaniz (joomla@adept.travel)
 * @copyright 2022 The Adept Traveler, Inc., All Rights Reserved.
 * @license BSD 2-Clause; See LICENSE.txt
 */

defined('_JEXEC') or die;

class PlgSystemRichSnippets extends \Joomla\CMS\Plugin\CMSPlugin
{
  /**
   * @var    \Joomla\CMS\Application\SiteApplication
   *
   * @since  3.9.0
   */
  protected $app;

  //public function onContentPrepare($context, &$data, &$params, $page = 0)
  public function onBeforeCompileHead()
  {
    $doc = $this->app->getDocument();
    $url = \Joomla\CMS\Uri\Uri::getInstance();
    $domain = \Joomla\CMS\Uri\Uri::root();
    $lang = \Joomla\CMS\Factory::getLanguage();
    $component = $this->app->input->getCmd('option', '');
    $view = $this->app->input->getCmd('view', '');
    $id = $this->app->input->getCmd('id', '');

    $context = $component . '.' . $view;

    if ($context == 'com_tags.tags') {
      $id = $this->app->input->getCmd('parent_id', '');
    }

    $id = preg_replace("/[^0-9]/", "", $id);

    if (
      $this->app->isClient('site')
      && $this->app->getDocument() instanceof \Joomla\CMS\Document\HtmlDocument
    ) {

      if (in_array($context, ['com_content.article', 'com_tags.tag'])) {

        // Author
        $author = $doc->getMetaData('author');

        if (empty($author) ||  $author == 'Super User') {
          $author = htmlspecialchars($this->app->getCfg('sitename'));
        }

        if (!empty($author)) {
          $doc->setMetadata('author', $author);
        }

        // Canonical URL
        if ($this->params->get('canonical')) {
          $href = (!empty($domain = $this->params->get('canonical_domain')))
            ? $domain . $url->getPath()
            : $url;

          if (($pos = strpos($href, '?')) !== false) {
            $href = substr($href, 0, $pos);
          }

          $doc->addCustomTag('<link rel="canonical" href="' . $href . '">');
        }
      }

      $db = \Joomla\CMS\Factory::getContainer()->get('DatabaseDriver');
      $data = new stdClass;

      switch ($context) {
        case 'com_content.category':
          $data = $this->getCategory($db, $id);
          break;

        case 'com_content.article':
          $data = $this->getArticle($db, $id);
          break;

        case 'com_tags.tags':
        case 'com_tags.tag':
          if (is_numeric($id)) {
            $data = $this->getTag($db, $id);
          } else {
            $data = new \stdClass();
          }

          break;
      }

      // Article Specific Tags
      if (in_array($context, [
        'com_content.article',
        'com_tags.tag'
      ])) {


        $doc->setMetaData('og:type', 'article', 'property');


        if (!empty($data->published)) {
          // Published Date/Time ie. 2018-06-27T14:40:00-06:00
          $doc->setMetaData('article:published_time', $data->published, 'property');
        }

        if (!empty($data->modified)) {
          // Modified Date/Time ie. 2018-06-27T14:40:00-06:00
          $doc->setMetaData('article:modified_time', $data->modified, 'property');
        }
      } else {
        $doc->setMetaData('og:type', 'website', 'property');
      }

      // Add Language    
      //$doc->setMetaData('og:locale', $lang->getTag(), 'property');

      // Add title
      $page = \Joomla\CMS\Uri\Uri::getInstance()->getPath();
      // Check if this is a content article
      if ($page == '/') {
        $title = $this->app->getCfg('sitename');
      } else {
        $title = (isset($data->title))
          // Set the title to the title of the article.
          ? $data->title .  ' - ' . $this->app->getCfg('sitename')
          // Use the pages title
          : $doc->getTitle();
      }

      // OpenGraph ie. Facebook
      $doc->setMetaData('og:title', $title, 'property');
      // Twitter
      $doc->setMetaData('twitter:title', $title);
      // Google+
      $doc->setMetaData('name', $title, 'itemprop');

      // Add description
      $desc = $doc->getMetaData("description");

      if (empty($desc)) {
        if (!empty($data->text)) {
          $desc = $data->text;
        } else if (!empty($doc->getDescription())) {
          $desc = $doc->getDescription();
        }
        /*
        else {

          switch ($context) {
            case 'com_content.category':
              //$data = $this->getCategory($db, $id);
              break;

            case 'com_content.article':
              //$data = $this->getArticle($db, $id);
              break;

            case 'com_tags.tags':
            case 'com_tags.tag':
              //

              break;
          }
        }
        */
      }



      if (!empty($desc)) {
        // Check if we should auto-generate the metatag description from the article
        if ($this->params->get('meta_desc', 0)) {
          // Base MetaTag Description
          $doc->setMetaData('description', $this->trimToChar(160, $desc));
        }

        // Facebook
        $doc->setMetaData('og:description', $desc, 'property');
        // Twitter
        $doc->setMetaData('twitter:description', $this->trimToChar(200, $desc), 'name');
        // Google+
        $doc->setMetaData('description', $desc, 'itemprop');
      }


      $imgOpenGraph = '';
      $imgTwitter = '';



      if (!empty($data->image)) {
        $imgOpenGraph = $this->getOptimizedImage($data->image, 'opengraph');
        $imgTwitter = $this->getOptimizedImage($data->image, 'twitter');
      } else {
        if (!empty($src = $this->params->get('facebook_logo'))) {
          $imgOpenGraph = $this->cleanLink($src);
        }

        if (!empty($src = $this->params->get('twitter_logo'))) {
          $imgTwitter = $this->cleanLink($src);
        }
      }

      if (!empty($imgOpenGraph)) {
        $doc->setMetaData(
          'og:image',
          $domain . '/' . $imgOpenGraph,
          'property'
        );
      }

      if (!empty($imgTwitter)) {
        $doc->setMetadata('twitter:card', 'summary_large_image');
        $doc->setMetaData(
          'twitter:image',
          $domain . '/' . $imgTwitter,
          'property'
        );
      }

      // Twitter Specific
      // Ref: https://developer.twitter.com/en/docs/tweets/optimize-with-cards/overview/markup

      if (
        !empty($twitterSite = $this->params->get('twitter_site'))
        || !empty($twitterSiteId = $this->params->get('twitter_site_id'))
        || !empty($twitterCreator = $this->params->get('twitter_creator'))
        || !empty($twitterCreatorId = $this->params->get('twitter_creator_id'))
      ) {

        //if (!empty($twitterLogo)) {
        //  $doc->setMetadata('twitter:card', 'summary_large_image');
        //}

        if (!empty($twitterSite)) {
          $doc->setMetaData('twitter:site', $twitterSite, 'property');
        }

        if (!empty($twitterSiteId)) {
          $doc->setMetaData('twitter:site:id', $twitterSiteId, 'property');
        }

        if (!empty($twitterCreator)) {
          $doc->setMetaData('twitter:creator', $twitterCreator, 'property');
        }

        if (!empty($twitterCreatorId)) {
          $doc->setMetaData('twitter:creator:id', $twitterCreatorId, 'property');
        }
      }

      //
      // OpenGraph Specific
      //
      $doc->setMetaData('og:site_name', $this->app->getCfg('sitename'), 'property');
      $doc->setMetaData('og:url', $url, 'property');

      //
      // Facebook
      //

      if (!empty($facebookPixel = $this->params->get('facebook_pixel'))) {
        $doc->addScript($facebookPixel);
      }
      /**/
    }
  }

  public static function trimToChar(int $length, string $text): string
  {
    // Trim text to passed length
    $text = substr($text, 0, $length);

    // Get last space before end of string
    $pos = strrpos($text, ' ');

    // Go back to last word
    $text = substr($text, 0, $pos);

    // Trim back till less then $length - 3
    while (strlen($text) > ($length - 3)) {
      // Get last space before end of string
      $pos = strrpos($text, ' ');
      // Trim back to next to last word
      $text = substr($text, 0, $pos);
    }

    $text .= '...';

    return $text;
  }

  protected function cleanLink(string $link): string
  {
    $uri = \Joomla\CMS\Uri\Uri::root();

    // Remove domain
    if (strpos($link, $uri) !== false) {
      $link = str_replace($uri, '/', $link);
    }

    // Remove anchor
    if (strpos($link, '#') !== false) {
      $link = substr($link, 0, strpos($link, '#'));
    }

    // Remove querystring
    if (strpos($link, '?') !== false) {
      $link = substr($link, 0, strpos($link, '?'));
    }

    return $link;
  }

  protected function cleanDescription($text): string
  {
    if ($pos = strpos($text, '</p>')) {
      $text = substr($text, 0,);
    }

    $text = strip_tags($text);
    $text = str_replace(array("\r", "\n"), '', $text);
    $text = trim(preg_replace('/\s+/', ' ', $text));

    return $text;
  }

  protected function getOptimizedImage(string $src, string $type): string
  {

    $path = JPATH_ROOT . '/images/' . $type;
    $src = $this->cleanLink($src);
    $src = (substr($src, 0, 1) == '/') ?: '/' . $src;
    $file = $path . $src;
    $src = JPATH_ROOT . $src;

    if (!file_exists($file) || filemtime($file) < filemtime($src)) {

      if (!file_exists($dir = substr($file, 0, strrpos($file, '/')))) {
        mkdir($dir, 0755, true);
      }

      $newWidth = 0;
      $newHeight = 0;

      if ($type == 'opengraph' || $type == 'facebook') {
        $newWidth = 1200;
        $newHeight = 630;
      } else if ($type == 'twitter') {
        $newWidth = 400;
        $newHeight = 219;
      }

      //imagecopyresampled($new,   $cur,    0, 0, 0,       0,                $newWidth, $newHeight, $orgWidth, $orgHeight);
      //imagecopyresampled($thumb, $source, 0, 0, $$pointX, $$pointY, $newWidth,        $newHeight,         $orgWidth,       $orgHeight);

      $newWidth = 288;
      $newHeight = 202; // my final thumb

      list($curWidth, $curHeight) = getimagesize($src);

      if ($newWidth > 0 && $newHeight > 0 && $curWidth > 0 && $curHeight > 0) {

        $newRatio = $newWidth / $newHeight; // ratio thumb
        $curRatio = $curWidth / $curHeight; // ratio original

        if ($curRatio >= $newRatio) {
          $orgHeight = $curHeight;
          $orgWidth = ceil(($orgHeight * $newWidth) / $newHeight);
          $pointX = ceil(($curWidth - $orgWidth) / 2);
          $pointY = 0;
        } else {
          $orgWidth = $curWidth;
          $orgHeight = ceil(($orgWidth * $newHeight) / $newWidth);
          $pointY = ceil(($curHeight - $orgHeight) / 2);
          $pointX = 0;
        }


        $cur = '';
        // Get the extension
        $ext = pathinfo($src, PATHINFO_EXTENSION);

        // Determine kind of file and load into $image
        switch ($ext) {
          case 'png':
            $cur = imagecreatefrompng($src);
            break;

          case 'jpg':
          case 'jpeg':
            $cur = imagecreatefromjpeg($src);
            break;

          default:
            break;
        }

        $new = imagecreatetruecolor($newWidth, $newHeight);
        //imagecopyresampled($new, $cur, 0, 0, $sourceX, $sourceY, $newWidth, $newHeight, $orgWidth, $orgHeight);
        imagecopyresampled($new, $cur, 0, 0, $pointX, $pointY, $newWidth, $newHeight, $orgWidth, $orgHeight);

        switch ($ext) {
          case 'png':
            imagepng($new, $file);
            break;

          case 'jpg':
          case 'jpeg':
            imagejpeg($new, $file);
            break;

          default:
            break;
        }
      }
    }

    return str_replace(JPATH_ROOT . '/', '', $file);
  }

  protected function getCategory($db, int $id)
  {
    $data = new stdClass();

    $query = $db->getQuery(true)
      ->select([
        $db->quoteName('a.title') . ' AS ' . $db->quoteName('title'),
        $db->quoteName('a.description') . ' AS ' . $db->quoteName('text'),
        $db->quoteName('a.created_time') . ' AS ' . $db->quoteName('created'),
        $db->quoteName('a.modified_time') . ' AS ' . $db->quoteName('modified'),
        $db->quoteName('a.params') . ' AS ' . $db->quoteName('params'),
        $db->quoteName('b.name') . ' AS ' . $db->quoteName('author')
      ])
      ->from($db->quoteName('#__categories', 'a'))
      ->join('INNER', $db->quoteName('#__users', 'b') . ' ON ' . $db->quoteName('a.created_user_id') . ' = ' . $db->quoteName('b.id'))
      ->where([
        $db->quoteName('a.id') . ' = :id'
      ])
      ->bind(':id', $id);

    $db->setQuery($query);

    try {
      $data = $db->loadObject();

      $data->text = $this->cleanDescription($data->text);

      $params = json_decode($data->params);
      $data->image = $params->image;

      $data->published = $data->created;

      unset($data->params);
    } catch (\RuntimeException $e) {
      //\Joomla\CMS\Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
      $data = new stdClass();
    }

    return $data;
  }

  protected function getArticle($db, int $id)
  {
    $query = $db->getQuery(true)
      ->select([
        $db->quoteName('a.title') . ' AS ' . $db->quoteName('title'),
        $db->quoteName('a.introtext') . ' AS ' . $db->quoteName('intro'),
        $db->quoteName('a.fulltext') . ' AS ' . $db->quoteName('text'),
        $db->quoteName('a.created') . ' AS ' . $db->quoteName('created'),
        $db->quoteName('a.modified') . ' AS ' . $db->quoteName('modified'),
        $db->quoteName('a.publish_up') . ' AS ' . $db->quoteName('published'),
        $db->quoteName('a.images') . ' AS ' . $db->quoteName('images'),
        $db->quoteName('b.name') . ' AS ' . $db->quoteName('author'),
        $db->quoteName('a.created_by_alias') . ' AS ' . $db->quoteName('alias'),
        $db->quoteName('a.metadesc') . ' AS ' . $db->quoteName('metadesc')
      ])
      ->from($db->quoteName('#__content', 'a'))
      ->join('INNER', $db->quoteName('#__users', 'b') . ' ON ' . $db->quoteName('a.created_by') . ' = ' . $db->quoteName('b.id'))
      ->where([
        $db->quoteName('a.id') . ' = :id'
      ])
      ->bind(':id', $id);

    $db->setQuery($query);

    try {
      $data = $db->loadObject();

      if (!empty($data->metadesc)) {
        $data->text = $data->metadesc;
      } else if (!empty($data->intro)) {
        $data->text = $data->intro;
      }
      unset($data->metadesc);
      unset($data->intro);

      $data->text = $this->cleanDescription($data->text);

      if (!empty($data->alias)) {
        $data->author = $data->alias;
      }

      unset($data->alias);

      $images = json_decode($data->images);
      unset($data->images);

      if (!empty($images->image_fulltext)) {
        $data->image = $images->image_fulltext;
      } else if (!empty($images->image_intro)) {
        $data->image = $images->image_intro;
      } else {
        $data->image = '';
      }
    } catch (\RuntimeException $e) {
      //\Joomla\CMS\Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
      $data = new stdClass();
    }

    return $data;
  }

  protected function getTag($db, int $id)
  {
    $query = $db->getQuery(true)
      ->select([
        $db->quoteName('a.title') . ' AS ' . $db->quoteName('title'),
        $db->quoteName('a.description') . ' AS ' . $db->quoteName('text'),
        $db->quoteName('a.created_user_id') . ' AS ' . $db->quoteName('created'),
        $db->quoteName('a.modified_time') . ' AS ' . $db->quoteName('modified'),
        $db->quoteName('a.publish_up') . ' AS ' . $db->quoteName('published'),
        $db->quoteName('a.images') . ' AS ' . $db->quoteName('images'),
        $db->quoteName('b.name') . ' AS ' . $db->quoteName('author'),
      ])
      ->from($db->quoteName('#__tags', 'a'))
      ->join('INNER', $db->quoteName('#__users', 'b') . ' ON ' . $db->quoteName('a.created_user_id') . ' = ' . $db->quoteName('b.id'))
      ->where([
        $db->quoteName('a.id') . ' = :id'
      ])
      ->bind(':id', $id);

    $db->setQuery($query);


    $data = $db->loadObject();
    if (isset($data) && is_object($data)) {
      $data->text = $this->cleanDescription($data->text);

      $images = json_decode($data->images);
      unset($data->images);

      if (!empty($images->image_fulltext)) {
        $data->image = $images->image_fulltext;
      } else if (!empty($images->image_intro)) {
        $data->image = $images->image_intro;
      } else {
        $data->image = '';
      }
    } else {
      $data = new stdClass();
    }

    return $data;
  }
}
