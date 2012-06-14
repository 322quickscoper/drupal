<?php

/**
 * @file
 * Definition of Drupal\book\Tests\BookTest.
 */

namespace Drupal\book\Tests;

use Drupal\node\Node;
use Drupal\simpletest\WebTestBase;

class BookTest extends WebTestBase {
  protected $book;
  // $book_author is a user with permission to create and edit books.
  protected $book_author;
  // $web_user is a user with permission to view a book
  // and access the printer-friendly version.
  protected $web_user;
  // $admin_user is a user with permission to create and edit books and to administer blocks.
  protected $admin_user;

  public static function getInfo() {
    return array(
      'name' => 'Book functionality',
      'description' => 'Create a book, add pages, and test book interface.',
      'group' => 'Book',
    );
  }

  function setUp() {
    parent::setUp(array('book', 'block', 'node_access_test'));

    // node_access_test requires a node_access_rebuild().
    node_access_rebuild();

    // Create users.
    $this->book_author = $this->drupalCreateUser(array('create new books', 'create book content', 'edit own book content', 'add content to books'));
    $this->web_user = $this->drupalCreateUser(array('access printer-friendly version', 'node test view'));
    $this->admin_user = $this->drupalCreateUser(array('create new books', 'create book content', 'edit own book content', 'add content to books', 'administer blocks', 'administer permissions', 'administer book outlines', 'node test view'));
  }

  /**
   * Create a new book with a page hierarchy.
   */
  function createBook() {
    // Create new book.
    $this->drupalLogin($this->book_author);

    $this->book = $this->createBookNode('new');
    $book = $this->book;

    /*
     * Add page hierarchy to book.
     * Book
     *  |- Node 0
     *   |- Node 1
     *   |- Node 2
     *  |- Node 3
     *  |- Node 4
     */
    $nodes = array();
    $nodes[] = $this->createBookNode($book->nid); // Node 0.
    $nodes[] = $this->createBookNode($book->nid, $nodes[0]->book['mlid']); // Node 1.
    $nodes[] = $this->createBookNode($book->nid, $nodes[0]->book['mlid']); // Node 2.
    $nodes[] = $this->createBookNode($book->nid); // Node 3.
    $nodes[] = $this->createBookNode($book->nid); // Node 4.

    $this->drupalLogout();

    return $nodes;
  }

  /**
   * Test book functionality through node interfaces.
   */
  function testBook() {
    // Create new book.
    $nodes = $this->createBook();
    $book = $this->book;

    $this->drupalLogin($this->web_user);

    // Check that book pages display along with the correct outlines and
    // previous/next links.
    $this->checkBookNode($book, array($nodes[0], $nodes[3], $nodes[4]), FALSE, FALSE, $nodes[0], array());
    $this->checkBookNode($nodes[0], array($nodes[1], $nodes[2]), $book, $book, $nodes[1], array($book));
    $this->checkBookNode($nodes[1], NULL, $nodes[0], $nodes[0], $nodes[2], array($book, $nodes[0]));
    $this->checkBookNode($nodes[2], NULL, $nodes[1], $nodes[0], $nodes[3], array($book, $nodes[0]));
    $this->checkBookNode($nodes[3], NULL, $nodes[2], $book, $nodes[4], array($book));
    $this->checkBookNode($nodes[4], NULL, $nodes[3], $book, FALSE, array($book));

    $this->drupalLogout();

    // Create a second book, and move an existing book page into it.
    $this->drupalLogin($this->book_author);
    $other_book = $this->createBookNode('new');
    $node = $this->createBookNode($book->nid);
    $edit = array('book[bid]' => $other_book->nid);
    $this->drupalPost('node/' . $node->nid . '/edit', $edit, t('Save'));

    $this->drupalLogout();
    $this->drupalLogin($this->web_user);

    // Check that the nodes in the second book are displayed correctly.
    // First we must set $this->book to the second book, so that the
    // correct regex will be generated for testing the outline.
    $this->book = $other_book;
    $this->checkBookNode($other_book, array($node), FALSE, FALSE, $node, array());
    $this->checkBookNode($node, NULL, $other_book, $other_book, FALSE, array($other_book));
  }

  /**
   * Check the outline of sub-pages; previous, up, and next; and printer friendly version.
   *
   * @param Drupal\node\Node $node
   *   Node to check.
   * @param $nodes
   *   Nodes that should be in outline.
   * @param $previous
   *   Previous link node.
   * @param $up
   *   Up link node.
   * @param $next
   *   Next link node.
   * @param $breadcrumb
   *   The nodes that should be displayed in the breadcrumb.
   */
  function checkBookNode(Node $node, $nodes, $previous = FALSE, $up = FALSE, $next = FALSE, array $breadcrumb) {
    // $number does not use drupal_static as it should not be reset
    // since it uniquely identifies each call to checkBookNode().
    static $number = 0;
    $this->drupalGet('node/' . $node->nid);

    // Check outline structure.
    if ($nodes !== NULL) {
      $this->assertPattern($this->generateOutlinePattern($nodes), t('Node ' . $number . ' outline confirmed.'));
    }
    else {
      $this->pass(t('Node ' . $number . ' doesn\'t have outline.'));
    }

    // Check previous, up, and next links.
    if ($previous) {
      $this->assertRaw(l('<b>‹</b> ' . $previous->title, 'node/' . $previous->nid, array('html'=> TRUE, 'attributes' => array('rel' => array('prev'), 'title' => t('Go to previous page')))), t('Previous page link found.'));
    }

    if ($up) {
      $this->assertRaw(l('up', 'node/' . $up->nid, array('html'=> TRUE,'attributes' => array('title' => t('Go to parent page')))), t('Up page link found.'));
    }

    if ($next) {
      $this->assertRaw(l($next->title . ' <b>›</b>', 'node/' . $next->nid, array('html'=> TRUE, 'attributes' => array('rel' => array('next'), 'title' => t('Go to next page')))), t('Next page link found.'));
    }

    // Compute the expected breadcrumb.
    $expected_breadcrumb = array();
    $expected_breadcrumb[] = url('');
    foreach ($breadcrumb as $a_node) {
      $expected_breadcrumb[] = url('node/' . $a_node->nid);
    }

    // Fetch links in the current breadcrumb.
    $links = $this->xpath('//nav[@class="breadcrumb"]/ol/li/a');
    $got_breadcrumb = array();
    foreach ($links as $link) {
      $got_breadcrumb[] = (string) $link['href'];
    }

    // Compare expected and got breadcrumbs.
    $this->assertIdentical($expected_breadcrumb, $got_breadcrumb, t('The breadcrumb is correctly displayed on the page.'));

    // Check printer friendly version.
    $this->drupalGet('book/export/html/' . $node->nid);
    $this->assertText($node->title, t('Printer friendly title found.'));
    $this->assertRaw(check_markup($node->body[LANGUAGE_NOT_SPECIFIED][0]['value'], $node->body[LANGUAGE_NOT_SPECIFIED][0]['format']), t('Printer friendly body found.'));

    $number++;
  }

  /**
   * Create a regular expression to check for the sub-nodes in the outline.
   *
   * @param array $nodes Nodes to check in outline.
   */
  function generateOutlinePattern($nodes) {
    $outline = '';
    foreach ($nodes as $node) {
      $outline .= '(node\/' . $node->nid . ')(.*?)(' . $node->title . ')(.*?)';
    }

    return '/<nav id="book-navigation-' . $this->book->nid . '"(.*?)<ul(.*?)' . $outline . '<\/ul>/s';
  }

  /**
   * Create book node.
   *
   * @param integer $book_nid Book node id or set to 'new' to create new book.
   * @param integer $parent Parent book reference id.
   */
  function createBookNode($book_nid, $parent = NULL) {
    // $number does not use drupal_static as it should not be reset
    // since it uniquely identifies each call to createBookNode().
    static $number = 0; // Used to ensure that when sorted nodes stay in same order.

    $edit = array();
    $langcode = LANGUAGE_NOT_SPECIFIED;
    $edit["title"] = $number . ' - SimpleTest test node ' . $this->randomName(10);
    $edit["body[$langcode][0][value]"] = 'SimpleTest test body ' . $this->randomName(32) . ' ' . $this->randomName(32);
    $edit['book[bid]'] = $book_nid;

    if ($parent !== NULL) {
      $this->drupalPost('node/add/book', $edit, t('Change book (update list of parents)'));

      $edit['book[plid]'] = $parent;
      $this->drupalPost(NULL, $edit, t('Save'));
    }
    else {
      $this->drupalPost('node/add/book', $edit, t('Save'));
    }

    // Check to make sure the book node was created.
    $node = $this->drupalGetNodeByTitle($edit['title']);
    $this->assertNotNull(($node === FALSE ? NULL : $node), t('Book node found in database.'));
    $number++;

    return $node;
  }

  /**
   * Tests book export ("printer-friendly version") functionality.
   */
  function testBookExport() {
    // Create a book.
    $nodes = $this->createBook();

    // Login as web user and view printer-friendly version.
    $this->drupalLogin($this->web_user);
    $this->drupalGet('node/' . $this->book->nid);
    $this->clickLink(t('Printer-friendly version'));

    // Make sure each part of the book is there.
    foreach ($nodes as $node) {
      $this->assertText($node->title, t('Node title found in printer friendly version.'));
      $this->assertRaw(check_markup($node->body[LANGUAGE_NOT_SPECIFIED][0]['value'], $node->body[LANGUAGE_NOT_SPECIFIED][0]['format']), t('Node body found in printer friendly version.'));
    }

    // Make sure we can't export an unsupported format.
    $this->drupalGet('book/export/foobar/' . $this->book->nid);
    $this->assertResponse('404', t('Unsupported export format returned "not found".'));

    // Make sure we get a 404 on a not existing book node.
    $this->drupalGet('book/export/html/123');
    $this->assertResponse('404', t('Not existing book node returned "not found".'));

    // Make sure an anonymous user cannot view printer-friendly version.
    $this->drupalLogout();

    // Load the book and verify there is no printer-friendly version link.
    $this->drupalGet('node/' . $this->book->nid);
    $this->assertNoLink(t('Printer-friendly version'), t('Anonymous user is not shown link to printer-friendly version.'));

    // Try getting the URL directly, and verify it fails.
    $this->drupalGet('book/export/html/' . $this->book->nid);
    $this->assertResponse('403', t('Anonymous user properly forbidden.'));
  }

  /**
   * Tests the functionality of the book navigation block.
   */
  function testBookNavigationBlock() {
    $this->drupalLogin($this->admin_user);

    // Set block title to confirm that the interface is available.
    $block_title = $this->randomName(16);
    $this->drupalPost('admin/structure/block/manage/book/navigation/configure', array('title' => $block_title), t('Save block'));
    $this->assertText(t('The block configuration has been saved.'), t('Block configuration set.'));

    // Set the block to a region to confirm block is available.
    $edit = array();
    $edit['blocks[book_navigation][region]'] = 'footer';
    $this->drupalPost('admin/structure/block', $edit, t('Save blocks'));
    $this->assertText(t('The block settings have been updated.'), t('Block successfully move to footer region.'));

     // Give anonymous users the permission 'node test view'.
     $edit = array();
     $edit[DRUPAL_ANONYMOUS_RID . '[node test view]'] = TRUE;
     $this->drupalPost('admin/people/permissions/' . DRUPAL_ANONYMOUS_RID, $edit, t('Save permissions'));
     $this->assertText(t('The changes have been saved.'), t("Permission 'node test view' successfully assigned to anonymous users."));

    // Test correct display of the block.
    $nodes = $this->createBook();
    $this->drupalGet('<front>');
    $this->assertText($block_title, t('Book navigation block is displayed.'));
    $this->assertText($this->book->title, t('Link to book root (@title) is displayed.', array('@title' => $nodes[0]->title)));
    $this->assertNoText($nodes[0]->title, t('No links to individual book pages are displayed.'));
  }

  /**
   * Test the book navigation block when an access module is enabled.
   */
   function testNavigationBlockOnAccessModuleEnabled() {
     $this->drupalLogin($this->admin_user);
     $edit = array();

     // Set the block title.
     $block_title = $this->randomName(16);
     $edit['title'] = $block_title;

     // Set block display to 'Show block only on book pages'.
     $edit['book_block_mode'] = 'book pages';
     $this->drupalPost('admin/structure/block/manage/book/navigation/configure', $edit, t('Save block'));
     $this->assertText(t('The block configuration has been saved.'), t('Block configuration set.'));

     // Set the block to a region to confirm block is available.
     $edit = array();
     $edit['blocks[book_navigation][region]'] = 'footer';
     $this->drupalPost('admin/structure/block', $edit, t('Save blocks'));
     $this->assertText(t('The block settings have been updated.'), t('Block successfully move to footer region.'));

     // Give anonymous users the permission 'node test view'.
     $edit = array();
     $edit[DRUPAL_ANONYMOUS_RID . '[node test view]'] = TRUE;
     $this->drupalPost('admin/people/permissions/' . DRUPAL_ANONYMOUS_RID, $edit, t('Save permissions'));
     $this->assertText(t('The changes have been saved.'), t('Permission \'node test view\' successfully assigned to anonymous users.'));

     // Create a book.
     $this->createBook();

     // Test correct display of the block to registered users.
     $this->drupalLogin($this->web_user);
     $this->drupalGet('node/' . $this->book->nid);
     $this->assertText($block_title, t('Book navigation block is displayed to registered users.'));
     $this->drupalLogout();

     // Test correct display of the block to anonymous users.
     $this->drupalGet('node/' . $this->book->nid);
     $this->assertText($block_title, t('Book navigation block is displayed to anonymous users.'));
   }

  /**
   * Tests the access for deleting top-level book nodes.
   */
   function testBookDelete() {
     $nodes = $this->createBook();
     $this->drupalLogin($this->admin_user);
     $edit = array();

     // Test access to delete top-level and child book nodes.
     $this->drupalGet('node/' . $this->book->nid . '/outline/remove');
     $this->assertResponse('403', t('Deleting top-level book node properly forbidden.'));
     $this->drupalPost('node/' . $nodes[4]->nid . '/outline/remove', $edit, t('Remove'));
     $node4 = node_load($nodes[4]->nid, NULL, TRUE);
     $this->assertTrue(empty($node4->book), t('Deleting child book node properly allowed.'));

     // Delete all child book nodes and retest top-level node deletion.
     foreach ($nodes as $node) {
       $nids[] = $node->nid;
     }
     node_delete_multiple($nids);
     $this->drupalPost('node/' . $this->book->nid . '/outline/remove', $edit, t('Remove'));
     $node = node_load($this->book->nid, NULL, TRUE);
     $this->assertTrue(empty($node->book), t('Deleting childless top-level book node properly allowed.'));
   }
}

