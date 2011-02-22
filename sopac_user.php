<?php
/**
 * SOPAC is The Social OPAC: a Drupal module that serves as a wholly integrated web OPAC for the Drupal CMS
 * This file contains the Drupal include functions for all the SOPAC admin pieces and configuration options
 * This file is called via hook_user
 *
 * @package SOPAC
 * @version 2.1
 * @author John Blyberg
 */

/**
 * This is a sub-function of the hook_user "view" operation.
 */
function sopac_user_view($op, &$edit, &$account, $category = NULL) {
  $locum = new locum_client;
  // SOPAC uses the first 7 characters of the MD5 hash instead of caching the user's password
  // like it used to do.  It's more secure this way, IMHO.
  $account->locum_pass = substr($account->pass, 0, 7);

  // Patron information table (top of the page)
  $patron_details_table = sopac_user_info_table($account, $locum);
  if (variable_get('sopac_summary_enable', 1)) {
    $result['patroninfo']['#title'] = t('Account Summary');
    $result['patroninfo']['#weight'] = 1;
    $result['patroninfo']['#type'] = 'user_profile_category';
    $result['patroninfo']['details']['#value'] = $patron_details_table;
  }
  
  // Patron checkouts (middle of the page)
  if ($account->valid_card && $account->bcode_verify) {
    $co_table = sopac_user_chkout_table($account, $locum);
    if ($co_table) {
      $result['patronco']['#title'] = t('Checked-out Items');
      $result['patronco']['#weight'] = 2;
      $result['patronco']['#type'] = 'user_profile_category';
      $result['patronco']['details']['#value'] = $co_table;
    }
  }

  // Patron holds (bottom of the page)
  if ($account->valid_card && $account->bcode_verify) {
    $holds_table = sopac_user_holds_table($account, $locum);
    if ($holds_table) {
      $result['patronholds']['#title'] = t('Requested Items');
      $result['patronholds']['#weight'] = 3;
      $result['patronholds']['#type'] = 'user_profile_category';
      $result['patronholds']['details']['#value'] = $holds_table;
    }
  }

  // Commit the page content
  $account->content[] = $result;
  
  // The Summary is not really needed.
  if (variable_get('sopac_history_hide', 1)) { unset($account->content['summary']); }
  unset($account->content['Preferences']);
}

/**
 * Returns a Drupal themed table of patron information for the "My Account" page.
 *
 * @param object $account Drupal user object for account being viewed
 * @param object $locum Instansiated Locum object
 * @return string Drupal themed table
 */
function sopac_user_info_table(&$account, &$locum) {

  $rows = array();
  
  // create home branch link if appropriate
  if ($account->profile_pref_home_branch) {
    $home_branch_link = l($account->profile_pref_home_branch, 'user/' . $account->uid . '/edit/Preferences');
  }
  elseif (variable_get('sopac_home_selector_options', FALSE)) {
    $home_branch_link = l(t('Click to select your home branch'), 'user/' . $account->uid . '/edit/Preferences');
  }
  else {$home_branch_link = NULL;}
  
  if ($account->profile_pref_cardnum) {
    $cardnum = $account->profile_pref_cardnum;
    $cardnum_link = '<a href="/user/' . $account->uid . '/edit/Preferences">' . $cardnum . '</a>';
    $userinfo = $locum->get_patron_info($cardnum);
    $bcode_verify = sopac_bcode_isverified($account);

    if ($bcode_verify) { $account->bcode_verify = TRUE; } else { $account->bcode_verify = FALSE; }
    if ($userinfo['pnum']) { $account->valid_card = TRUE; } else { $account->valid_card = FALSE; }

    // Construct the user details table based on what is configured in the admin interface
    if ($account->valid_card && $bcode_verify) {
      if (variable_get('sopac_pname_enable', 1)) {
        $rows[] = array(array('data' => t('Patron Name'), 'class' => 'attr_name'), $userinfo['name']);
      }
      if (variable_get('sopac_lcard_enable', 1)) {
        $rows[] = array(array('data' => t('Library Card Number'), 'class' => 'attr_name'), $cardnum_link);
      }
      // add row for home branch if appropriate
      if ($home_branch_link) {
        $rows[] = array(array('data' => t('Home Branch'), 'class' => 'attr_name'), $home_branch_link);
      }
      if (variable_get('sopac_numco_enable', 1)) {
        $rows[] = array(array('data' => t('Items Checked Out'), 'class' => 'attr_name'), $userinfo['checkouts']);
      }
      if (variable_get('sopac_fines_display', 1) && variable_get('sopac_fines_enable', 1)) {
        $amount_link = '<a href="/user/fines">$' . number_format($userinfo['balance'], 2, '.', '') . '</a>';
        $rows[] = array(array('data' => t('Fine Balance'), 'class' => 'attr_name'), $amount_link);
      }
      if (variable_get('sopac_cardexp_enable', 1)) {
        $rows[] = array(array('data' => t('Card Expiration Date'), 'class' => 'attr_name'), date('m-d-Y', $userinfo['expires']));
      }
      if (variable_get('sopac_tel_enable', 1)) {
        $rows[] = array(array('data' => t('Telephone'), 'class' => 'attr_name'), $userinfo['tel1']);
      }
    } else {
      $rows[] = array(array('data' => t('Library Card Number'), 'class' => 'attr_name'), $cardnum_link);
    }
  } else {
    $cardnum_link = '<a href="/user/' . $account->uid . '/edit/Preferences">' . t('Click to add your library card') . '</a>';
    $rows[] = array(array('data' => t('Library Card Number'), 'class' => 'attr_name'), $cardnum_link);
    // add row for home branch if appropriate
    if ($home_branch_link) {
      $rows[] = array(array('data' => t('Home Branch'), 'class' => 'attr_name'), $home_branch_link);
    }
  }

  if ($account->mail && variable_get('sopac_email_enable', 1)) { 
    $rows[] = array(array('data' => t('Email'), 'class' => 'attr_name'), $account->mail);
  }

  // Begin creating the user information display content
  $user_info_disp = theme('table', NULL, $rows, array('id' => 'patroninfo-summary', 'cellspacing' => '0'));
  
  if ($account->valid_card && !$bcode_verify) {
    
    $user_info_disp .= '<div class="error">' . variable_get('sopac_uv_cardnum', t('The card number you have provided has not yet been verified by you.  In order to make sure that you are the rightful owner of this library card number, we need to ask you some simple questions.')) . '</div>' . drupal_get_form('sopac_bcode_verify_form', $account->uid, $cardnum);
    
  } else if ($cardnum && !$account->valid_card) {
    
    $user_info_disp .= '<div class="error">' . variable_get('sopac_invalid_cardnum', t('It appears that the library card number stored on our website is invalid. If you have received a new card, or feel that this is an error, please click on the card number above to change it to your most recent library card. If you need further help, please contact us.')) . '</div>';
    
  }

  return $user_info_disp;
}

/**
 * Returns a Drupal-themed table of checked-out items as well as the renewal form functionality.
 *
 * @param object $account Drupal user object for account being viewed
 * @param object $locum Instansiated Locum object
 * @return string Drupal themed table
 */
function sopac_user_chkout_table(&$account, &$locum, $max_disp = NULL) {

  // Process any renew requests that have been submitted
  if ($_POST['sub_type'] == 'Renew Selected') {
    if (count($_POST['inum'])) {
      foreach ($_POST['inum'] as $inum => $varname) {
        $items[$inum] = $varname;
      }
      $renew_status = $locum->renew_items($account->profile_pref_cardnum, $account->locum_pass, $items);
    }
  } else if ($_POST['sub_type'] == 'Renew All') {
    $renew_status = $locum->renew_items($account->profile_pref_cardnum, $account->locum_pass, 'all');
  }

  // Create the check-outs table
  $rows = array();
  if ($account->profile_pref_cardnum) {
    $locum_pass = substr($account->pass, 0, 7);
    $cardnum = $account->profile_pref_cardnum;
    $checkouts = $locum->get_patron_checkouts($cardnum, $locum_pass);

    if (!count($checkouts)) { return t('No items checked out.'); }
    $sopac_prefix = variable_get('sopac_url_prefix', 'cat/seek');
    $header = array('',t('Title'),t('Due Date'));
    foreach ($checkouts as $co) {
      if ($renew_status[$co['inum']]['error']) {
        $duedate = '<span style="color: red;">' . $renew_status[$co['inum']]['error'] . '</span>';
      } else {
        if (time() > $co['duedate']) {
          $duedate = '<span style="color: red;">' . date('m-d-Y', $co['duedate']) . '</span>';
        } else {
          $duedate = date('m-d-Y', $co['duedate']);
        }
      }

      $url = url($sopac_prefix . '/record/' . $co['bnum']);
      $rows[] = array(
        '<input type="checkbox" name="inum[' . $co['inum'] . ']" value="' . $co['varname'] . '">',
        '<a href="' . $url . '">' . $co['title'] . '</a>',
        $duedate,
      );
    }
    $submit_buttons = '<input type="submit" name="sub_type" value="' . t('Renew Selected') . '"> <input type="submit" name="sub_type" value="' . t('Renew All') . '">';
    $rows[] = array( 'data' => array(array('data' => $submit_buttons, 'colspan' => 3)), 'class' => 'profile_button' );
  } else {
    return FALSE;
  }
  
  // Wrap it together inside a form
  $content = '<form method="post">' . theme('table', $header, $rows, array('id' => 'patroninfo', 'cellspacing' => '0')) . '</form>';
  return $content;
}

/**
 * Returns a Drupal-themed table of on-hold items as well as the renewal form functionality.
 *
 * @param object $account Drupal user object for account being viewed
 * @param object $locum Instansiated Locum object
 * @return string Drupal themed table
 */
function sopac_user_holds_table(&$account, &$locum) {
  
  // Process any holds deletions that have been submitted
  $freezes_enabled = variable_get('sopac_hold_freezes_enable', 1);
  $submit_value = $freezes_enabled ? 'Update Holds' : 'Cancel Selected Holds';
  $update_holds = FALSE;

  if ($account->profile_pref_cardnum) {
    $cardnum = $account->profile_pref_cardnum;
    $holds = $locum->get_patron_holds($cardnum, $account->locum_pass);
    
    if ($_POST['sub_type'] == $submit_value) {
      
      // Queue up cancellations
      $cancel_arr = array();
      if (count($_POST['cancel'])) {
        $cancel_arr = $_POST['cancel'];
        $update_holds = TRUE;
      }
      
      // Queue up freezes
      $freeze_arr = array();
      foreach ($holds as $hold) {
        if (isset($_POST['freeze'][$hold['bnum']])) {
          $freeze_arr[$hold['bnum']] = 1;
          $update_holds = TRUE;
        } else if ($hold['is_frozen'] == 1) {
          $freeze_arr[$hold['bnum']] = 0;
          $update_holds = TRUE;
        }
      }
      
      if ($update_holds) {
        $locum->update_holds($cardnum, $account->locum_pass, $cancel_arr, $freeze_arr, NULL);
        $holds = $locum->get_patron_holds($cardnum, $account->locum_pass);
      }
    }
    
    if (!count($holds)) { return t('No items on hold.'); }
    
    if ($freezes_enabled) {
      $header = array('Delete', 'Title', 'Status', 'Pickup Location', 'Freeze');
    }
    else {
      $header = array('', 'Title', 'Status', 'Pickup Location');
    }
    
    $rows = array();
    $sopac_prefix = variable_get('sopac_url_prefix', 'cat/seek');
    foreach ($holds as $hold) {
      $hold_pickup_loc = $hold['pickuploc']['options'][$hold['pickuploc']['selected']];

      if ($hold['can_freeze']) {
        $freezer = 
        '<input type="checkbox" name="freeze[' . $hold['bnum'] . ']" value="1"' . (($hold['is_frozen']) ? 'checked=checked' : '') . '>';
      } else {
        $freezer = '&nbsp;';
      }
      
      $url = url($sopac_prefix . '/record/' . $hold['bnum']);
      $row = array(
        '<input type="checkbox" name="cancel[' . $hold['bnum'] . ']" value="1">',
        '<a href="' . $url . '">' . $hold['title'] . '</a>',
        $hold['status'],
        $hold_pickup_loc,
      );
      
      if ($freezes_enabled) {
        $row[] = $freezer;
      }
      $rows[] = $row;
    }
    
    $submit_button = '<input type="submit" name="sub_type" value="' . $submit_value . '">';
    $rows[] = array( 'data' => array(array('data' => $submit_button, 'colspan' => $freezes_enabled ? 5 : 4)), 'class' => 'profile_button' );
  } else {
    return FALSE;
  }
  $content = '<form method="post">' . theme('table', $header, $rows, array('id' => 'patroninfo', 'cellspacing' => '0')) . '</form>';
  return $content;
}

/**
 * A dedicated check-outs page to list all checkouts.
 */
function sopac_checkouts_page() {
  global $user;
  
  $account = user_load($user->uid);
  $cardnum = $account->profile_pref_cardnum;
  $locum = new locum_client;
  $userinfo = $locum->get_patron_info($cardnum);
  $bcode_verify = sopac_bcode_isverified($account);
  if ($bcode_verify) { $account->bcode_verify = TRUE; } else { $account->bcode_verify = FALSE; }
  if ($userinfo['pnum']) { $account->valid_card = TRUE; } else { $account->valid_card = FALSE; }
  profile_load_profile(&$user);
  
  if ($account->valid_card && $bcode_verify) {
    $content = sopac_user_chkout_table(&$user, &$locum);
  } else if ($account->valid_card && !$bcode_verify) {
    $content = '<div class="error">' . variable_get('sopac_uv_cardnum', t('The card number you have provided has not yet been verified by you.  In order to make sure that you are the rightful owner of this library card number, we need to ask you some simple questions.')) . '</div>' . drupal_get_form('sopac_bcode_verify_form', $account->uid, $cardnum);
  } else if ($cardnum && !$account->valid_card) {
    $content = '<div class="error">' . variable_get('sopac_invalid_cardnum', t('It appears that the library card number stored on our website is invalid. If you have received a new card, or feel that this is an error, please click on the card number above to change it to your most recent library card. If you need further help, please contact us.')) . '</div>';
  } else if (!$user->uid) {
    $content = '<div class="error">' . t('You must be <a href="/user">logged in</a> to view this page.') . '</div>';
  } else if (!$cardnum) {
    $content = '<div class="error">' . t('You must register a valid <a href="/user/' . $user->uid . '/edit/Preferences">library card number</a> to view this page.') . '</div>';
  }
  
  return $content;
}

/**
 * A dedicated checkout history page.
 */
function sopac_checkout_history_page() {
  global $user;
  profile_load_profile(&$user);
  if ($user->profile_pref_cardnum) {
    $locum = new locum_client();
    $locum_pass = substr($user->pass, 0, 7);
    $cardnum = $user->profile_pref_cardnum;
    $checkouts = $locum->get_patron_checkout_history($cardnum, $locum_pass);

    if (!is_array($checkouts)) {
      if ($checkouts == 'out') {
        $content = '<div>This feature is currently turned off.</div>';
        $toggle = l('Opt In', 'user/checkouts/history/opt/in');
      }
      if ($checkouts == 'in') {
        $content = '<div>There are no items in your checkout history.</div>';
        $toggle = l('Opt Out', 'user/checkouts/history/opt/out');
      }
    }
    else {
      $toggle = l('Opt Out', 'user/checkouts/history/opt/out');
      // Create the checkout history table
      $header = array('Title', 'Author', 'Checked Out', 'Details');
      $rows = array();
      foreach ($checkouts as $item) {
        $url = url($sopac_prefix . '/record/' . $item['bnum']);
        $rows[] = array(
          '<a href="' . $url . '">' . $item['title'] . '</a>',
          $item['author'],
          $item['date'],
          $item['details'],
        );
      }
      $content = theme('table', $header, $rows, array('id' => 'patroninfo', 'cellspacing' => '0'));
    }
    $content .= '<div>' . $toggle . '</div>';
  }
  else {$content = '<div>' . t('Please register your library card to take advantage of this feature.') . '</div>';}
  return $content;
}

/**
 * Handle toggling checkout history on or off.
 */
function sopac_checkout_history_toggle($action) {
  global $user;
  if ($action != 'in' && $action != 'out') { drupal_goto('user/checkouts/history'); }
  $adjective = $action == 'in' ? t('on') : t('off');
  profile_load_profile(&$user);
  if ($user->profile_pref_cardnum) {
    if (!$_GET['confirm']) {
      $confirm_link = l('confirm', $_GET['q'], array('query' => 'confirm=true'));
      $content = "<div>Please $confirm_link that you wish to turn $adjective your checkout history.";
      if ($action == 'out') {
        $content .= ' ' . t('Please note: this will delete your entire checkout history.');
      }
    }
    else {
      $locum = new locum_client();
      $locum_pass = substr($user->pass, 0, 7);
      $cardnum = $user->profile_pref_cardnum;
      $success = $locum->set_patron_checkout_history($cardnum, $locum_pass, $action);
      if ($success === TRUE) {
        $content = "<div>Your checkout history has been turned $adjective.</div>";
      } else {
        $content = "<div>An error occurred. Your checkout history has not been turned $adjective. Please try again.</div>";
      }
    }
  }
  else {$content = '<div>' . t('Please register your library card to take advantage of this feature.') . '</div>';}
  return $content;
}

/**
 * A dedicated holds page to list all holds.
 */
function sopac_holds_page() {
  global $user;
  
  $account = user_load($user->uid);
  $cardnum = $account->profile_pref_cardnum;
  $locum = new locum_client;
  $userinfo = $locum->get_patron_info($cardnum);
  $bcode_verify = sopac_bcode_isverified($account);
  if ($bcode_verify) { $account->bcode_verify = TRUE; } else { $account->bcode_verify = FALSE; }
  if ($userinfo['pnum']) { $account->valid_card = TRUE; } else { $account->valid_card = FALSE; }
  profile_load_profile(&$user);
  
  if ($account->valid_card && $bcode_verify) {
    $content = sopac_user_holds_table(&$user, &$locum);
  } else if ($account->valid_card && !$bcode_verify) {
    $content = '<div class="error">' . variable_get('sopac_uv_cardnum', t('The card number you have provided has not yet been verified by you.  In order to make sure that you are the rightful owner of this library card number, we need to ask you some simple questions.')) . '</div>' . drupal_get_form('sopac_bcode_verify_form', $account->uid, $cardnum);
  } else if ($cardnum && !$account->valid_card) {
    $content = '<div class="error">' . variable_get('sopac_invalid_cardnum', t('It appears that the library card number stored on our website is invalid. If you have received a new card, or feel that this is an error, please click on the card number above to change it to your most recent library card. If you need further help, please contact us.')) . '</div>';
  } else if (!$user->uid) {
    $content = '<div class="error">' . t('You must be <a href="/user">logged in</a> to view this page.') . '</div>';
  } else if (!$cardnum) {
    $content = '<div class="error">' . t('You must register a valid <a href="/user/' . $user->uid . '/edit/Preferences">library card number</a> to view this page.') . '</div>';
  }
  
  return $content;
}

/**
 * A dedicated page for managing fines and payments.
 */
function sopac_fines_page() {
  global $user;

  $locum = new locum_client;
  profile_load_profile(&$user);

  if ($user->profile_pref_cardnum && sopac_bcode_isverified(&$user)) {
    $locum_pass = substr($user->pass, 0, 7);
    $cardnum = $user->profile_pref_cardnum;
    $fines = $locum->get_patron_fines($cardnum, $locum_pass);
    
    if (!count($fines)) {
      $notice = t('You do not have any fines, currently.');
    } else {
      $header = array('',t('Amount'),t('Description'));
      $fine_total = (float) 0;
      foreach ($fines as $fine) {
        $col1 = variable_get('sopac_payments_enable', 1) ? '<input type="checkbox" name="varname[]" value="' . $fine['varname'] . '">' : '';
        $rows[] = array(
          $col1,
          '$' . number_format($fine['amount'], 2),
          $fine['desc'],
        );
        $hidden_vars .= '<input type="hidden" name="fine_summary[' . $fine['varname'] . '][amount]" value="' . addslashes($fine['amount']) . '">';
        $hidden_vars .= '<input type="hidden" name="fine_summary[' . $fine['varname'] . '][desc]" value="' . addslashes($fine['desc']) . '">';
        $fine_total = $fine_total + $fine['amount'];
      }
      $rows[] = array('<strong>Total:</strong>', '$' . number_format($fine_total, 2), '');
      $submit_button = '<input type="submit" value="' . t('Pay Selected Charges') . '">';
      if (variable_get('sopac_payments_enable', 1)) {
        $rows[] = array( 'data' => array(array('data' => $submit_button, 'colspan' => 3)), 'class' => 'profile_button' );
      }
      $fine_table = '<form method="post" action="/user/fines/pay">' . theme('table', $header, $rows, array('id' => 'patroninfo', 'cellspacing' => '0')) . $hidden_vars . '</form>';
      $notice = t('Your current fine balance is $') . number_format($fine_total, 2) . '.';
    }
  } else {
    $notice = t('You do not yet have a library card validated with our system.  You can add and validate a card using your <a href="/user">account page</a>.');
  }
  
  $result_page = theme('sopac_fines', $notice, $fine_table, &$user);
  return '<p>'. t($result_page) .'</p>';
}

/**
 * A dedicated page for viewing payment information.
 */
function sopac_finespaid_page() {
  global $user;
  $limit = 20; // TODO Make this configurable
  
  if (count($_POST['payment_id'])) {
    foreach ($_POST['payment_id'] as $pid) {
      db_query('DELETE FROM {sopac_fines_paid} WHERE payment_id = ' . $pid . ' AND uid = ' . $user->uid);
    }
  }
  
  if (db_result(db_query('SELECT COUNT(*) FROM {sopac_fines_paid} WHERE uid = ' . $user->uid))) {
    $header = array('','Payment Date', 'Payment Description','Amount');
    $dbq = pager_query('SELECT payment_id, UNIX_TIMESTAMP(trans_date) as trans_date, fine_desc, amount FROM {sopac_fines_paid} WHERE uid = ' . $user->uid . ' ORDER BY trans_date DESC', $limit);
    while ($payment_arr = db_fetch_array($dbq)) {
      $checkbox = '<input type="checkbox" name="payment_id[]" value="' . $payment_arr['payment_id'] . '">';
      $payment_date = date('m-d-Y, H:i:s', $payment_arr['trans_date']);
      $payment_desc = $payment_arr['fine_desc'];
      $payment_amt = '$' . number_format($payment_arr['amount'], 2);
      $rows[] = array($checkbox, $payment_date, $payment_desc, $payment_amt);
    }
    $submit_button = '<input type="submit" value="' . t('Remove Selected Payment Records') . '">';
    $rows[] = array( 'data' => array(array('data' => $submit_button, 'colspan' => 4)), 'class' => 'profile_button' );
    $page_disp = '<form method="post">' . theme('pager', NULL, $limit, 0, NULL, 6) . theme('table', $header, $rows, array('id' => 'patroninfo', 'cellspacing' => '0')) . '</form>';
  } else {
    $page_disp = t('You do not have any payments on record.');
  }

  return $page_disp;
}

function sopac_makepayment_page() {
  global $user;

  $locum = new locum_client;
  profile_load_profile(&$user);

  if ($user->profile_pref_cardnum && sopac_bcode_isverified(&$user)) {
    if ($_POST['varname'] && is_array($_POST['varname'])) {
      $varname = $_POST['varname'];
    } else {
      $varname = explode('|', $_POST['varname']);
    }
    $locum_pass = substr($user->pass, 0, 7);
    $cardnum = $user->profile_pref_cardnum;
    $fines = $locum->get_patron_fines($cardnum, $locum_pass);
    if (!count($fines) || !count($varname)) {
      $notice = t('You did not select any payable fines.');
    } else {
      $header = array('', t('Amount'),t('Description'));
      $fine_total = (float) 0;
      foreach ($fines as $fine) {
        if (in_array($fine['varname'], $varname)) {
          $rows[] = array(
            '',
            '$' . number_format($fine['amount'], 2),
            $fine['desc'],
          );
          $fine_total = $fine_total + $fine['amount'];
          $hidden_vars_arr[$fine['varname']]['amount'] = $_POST['fine_summary'][$fine['varname']]['amount'];
          $hidden_vars_arr[$fine['varname']]['desc'] = $_POST['fine_summary'][$fine['varname']]['desc'];
        }
      }
      $payment_form = drupal_get_form('sopac_fine_payment_form', $varname, (string) $fine_total, $hidden_vars_arr);
      $rows[] = array('<strong>Total:</strong>', '$' . number_format($fine_total, 2), '') ;
      $fine_table = theme('table', $header, $rows, array('id' => 'patroninfo', 'cellspacing' => '0'));
      $notice = t('You have selected to pay the following fines:');
    }
  } else {
    $notice = t('You do not yet have a library card validated with our system.  You can add and validate a card using your <a href="/user">account page</a>.');
  }
  
  $result_page = theme('sopac_fines', $notice, $fine_table, &$user, $payment_form);
  return '<p>'. t($result_page) .'</p>';
}

function sopac_fine_payment_form() {
  global $user;
  
  $args = func_get_args();
  $varname = $args[1];
  $fine_total = $args[2];
  $hidden_vars_arr = $args[3];

  $form['#redirect'] = 'user/fines';
  $form['sopac_payment_billing_info'] = array(
    '#type' => 'fieldset',
    '#title' => t('Billing Information'),
    '#collapsible' => FALSE,
  );

  $form['sopac_payment_billing_info']['name'] = array(
    '#type' => 'textfield',
    '#title' => t('Name on the credit card'),
    '#size' => 48,
    '#maxlength' => 200,
    '#required' => TRUE,
  );
  
  $form['sopac_payment_billing_info']['address1'] = array(
    '#type' => 'textfield',
    '#title' => t('Billing Address'),
    '#size' => 48,
    '#maxlength' => 200,
    '#required' => TRUE,
  );
  
  $form['sopac_payment_billing_info']['city'] = array(
    '#type' => 'textfield',
    '#title' => t('City/Town'),
    '#size' => 32,
    '#maxlength' => 200,
    '#required' => TRUE,
  );
  
  $form['sopac_payment_billing_info']['state'] = array(
    '#type' => 'textfield',
    '#title' => t('State'),
    '#size' => 3,
    '#maxlength' => 2,
    '#required' => TRUE,
  );
  
  $form['sopac_payment_billing_info']['zip'] = array(
    '#type' => 'textfield',
    '#title' => t('ZIP Code'),
    '#size' => 7,
    '#maxlength' => 200,
    '#required' => TRUE,
  );
  
  $form['sopac_payment_billing_info']['email'] = array(
    '#type' => 'textfield',
    '#title' => t('Email Address'),
    '#size' => 48,
    '#maxlength' => 200,
    '#required' => TRUE,
  );
  
  $form['sopac_payment_cc_info'] = array(
    '#type' => 'fieldset',
    '#title' => t('Credit Card Information'),
    '#collapsible' => FALSE,
  );

  $form['sopac_payment_cc_info']['ccnum'] = array(
    '#type' => 'textfield',
    '#title' => t('Credit Card Number'),
    '#size' => 24,
    '#maxlength' => 20,
    '#required' => TRUE,
  );
  
  $form['sopac_payment_cc_info']['ccexpmonth'] = array(
    '#type' => 'textfield',
    '#title' => t('Expiration Month'),
    '#size' => 4,
    '#maxlength' => 2,
    '#required' => TRUE,
    '#prefix' => '<table class="fines-form-table"><tr><td>',
    '#suffix' => '</td>',
  );
  
  $form['sopac_payment_cc_info']['ccexpyear'] = array(
    '#type' => 'textfield',
    '#title' => t('Expiration Year'),
    '#size' => 5,
    '#maxlength' => 4,
    '#required' => TRUE,
    '#prefix' => '<td>',
    '#suffix' => '</td></tr></table>',
  );
  
  $form['sopac_payment_cc_info']['ccseccode'] = array(
    '#type' => 'textfield',
    '#title' => t('Security Code'),
    '#size' => 5,
    '#maxlength' => 5,
    '#required' => TRUE,
  );
  
  foreach ($hidden_vars_arr as $hkey => $hvar) {
    $form['sopac_payment_form']['fine_summary[' . $hkey . '][amount]'] = array('#type' => 'hidden', '#value' => $hvar['amount']);
    $form['sopac_payment_form']['fine_summary[' . $hkey . '][desc]'] = array('#type' => 'hidden', '#value' => $hvar['desc']);
  }
  $form['sopac_payment_form']['varname'] = array('#type' => 'hidden', '#value' => implode('|', $varname));
  $form['sopac_payment_form']['total'] = array('#type' => 'hidden', '#value' => $fine_total);
  $form['sopac_savesearch_form']['submit'] = array('#type' => 'submit', '#value' => t('Make Payment'));

  return $form;
}

function sopac_fine_payment_form_submit($form, &$form_state) {
  global $user;
  $locum = new locum_client;
  profile_load_profile(&$user);
  $locum_pass = substr($user->pass, 0, 7);

  if ($user->profile_pref_cardnum && sopac_bcode_isverified(&$user)) {
    $fines = $locum->get_patron_fines($cardnum, $locum_pass);
    $payment_details['name'] = $form_state['values']['name'];
    $payment_details['address1'] = $form_state['values']['address1'];
    $payment_details['city'] = $form_state['values']['city'];
    $payment_details['state'] = $form_state['values']['state'];
    $payment_details['zip'] = $form_state['values']['zip'];
    $payment_details['email'] = $form_state['values']['email'];
    $payment_details['ccnum'] = $form_state['values']['ccnum'];
    $payment_details['ccexpmonth'] = $form_state['values']['ccexpmonth'];
    $payment_details['ccexpyear'] = $form_state['values']['ccexpyear'];
    $payment_details['ccseccode'] = $form_state['values']['ccseccode'];
    $payment_details['total'] = $form_state['values']['total'];
    $payment_details['varnames'] = explode('|', $form_state['values']['varname']);
    $payment_result = $locum->pay_patron_fines($user->profile_pref_cardnum, $locum_pass, $payment_details);

    if (!$payment_result['approved']) {
      if ($payment_result['reason']) {
        $error = '<strong>' . t('Your payment was not processed:') . '</strong> ' . $payment_result['reason'];
      } else {
        $error = t('We were unable to process your payment.');
      }
      drupal_set_message(t('<span class="fine-notice">' . $error . '</span>'));
      if ($payment_result['error']) {
        drupal_set_message(t('<span class="fine-notice">' . $payment_result['error'] . '</span>'));
      }
    } else {
      foreach ($_POST['fine_summary'] as $fine_var => $fine_var_arr) {
        $fine_desc = db_escape_string($fine_var_arr['desc']);
        $sql = 'INSERT INTO {sopac_fines_paid} (payment_id, uid, amount, fine_desc) VALUES (0, ' . $user->uid . ', ' . $fine_var_arr['amount'] . ', "' . $fine_desc . '")';
        db_query($sql);
      }
      $amount = '$' . number_format($form_state['values']['total'], 2);
      drupal_set_message('<span class="fine-notice">' . t('Your payment of ') . $amount . t(' was successful.  Thank-you!') . '</span>');
    }
  }
}


/**
 * A dedicated page for showing and managing saved searches from the catalog.
 */
function sopac_saved_searches_page() {
  global $user;
  $limit = 20; // TODO Make this configurable
  
  if (count($_POST['search_id'])) {
    foreach ($_POST['search_id'] as $sid) {
      db_query('DELETE FROM {sopac_saved_searches} WHERE search_id = ' . $sid . ' AND uid = ' . $user->uid);
    }
  }
  
  if (db_result(db_query('SELECT COUNT(*) FROM {sopac_saved_searches} WHERE uid = ' . $user->uid))) {
    $header = array('','Search Description','');
    $dbq = pager_query('SELECT * FROM {sopac_saved_searches} WHERE uid = ' . $user->uid . ' ORDER BY savedate DESC', $limit);
    while ($search_arr = db_fetch_array($dbq)) {
      $checkbox = '<input type="checkbox" name="search_id[]" value="' . $search_arr['search_id'] . '">';
      $search_desc = '<a href="' . $search_arr['search_url'] . '">' . $search_arr['search_desc']. '</a>';
      // TODO: implement RSS feeds for saved searches.
      $search_feed_url = sopac_update_url($search_arr['search_url'], 'output', 'rss');
      $search_feed = theme_feed_icon($search_feed_url, 'RSS Feed: ' . $search_arr['search_desc']);
      $rows[] = array($checkbox, $search_desc, $search_feed);
    }
    $submit_button = '<input type="submit" value="' . t('Remove Selected Searches') . '">';
    $rows[] = array( 'data' => array(array('data' => $submit_button, 'colspan' => 3)), 'class' => 'profile_button' );
    $page_disp = '<form method="post">' . theme('pager', NULL, $limit, 0, NULL, 6) . theme('table', $header, $rows, array('id' => 'patroninfo', 'cellspacing' => '0')) . '</form>';
  } else {
    $page_disp = '<div class="overview-nodata">' . t('You do not currently have any saved searches.') . '</div>';
  }

  return $page_disp;
}


/**
 * Returns the form array for saving searches
 *
 * @return array Drupal form array.
 */
function sopac_savesearch_form() {
  global $user;
  
  $search_args = '/' . variable_get('sopac_url_prefix', 'cat/seek') . '/search' . substr($_SERVER['REQUEST_URI'], 12 + strlen(variable_get('sopac_url_prefix', 'cat/seek')));
  $uri_arr = sopac_parse_uri();
  $term_arr = explode('?', $uri_arr[2]);
  
  $form_desc = t('How would you like to label your ') . $uri_arr[1] . t(' search for ') . '"<a href="' . $search_args . '">'. $term_arr[0] . '</a>" ?';
  $form['#redirect'] = 'user/library/searches';
  $form['sopac_savesearch_form'] = array(
    '#type' => 'fieldset',
    '#title' => t($form_desc),
    '#collapsible' => FALSE,
  );

  $form['sopac_savesearch_form']['searchname'] = array(
    '#type' => 'textfield',
    '#title' => t('Search Label'),
    '#size' => 48,
    '#maxlength' => 128,
    '#required' => TRUE,
    '#default_value' => t('My custom ') . $uri_arr[1] . t(' search for ') . '"' . $term_arr[0] . '"',
  );
  
  $form['sopac_savesearch_form']['uri'] = array('#type' => 'hidden', '#value' => $search_args);
  $form['sopac_savesearch_form']['submit'] = array('#type' => 'submit', '#value' => t('Save'));

  return $form;
  
}


function sopac_savesearch_form_submit($form, &$form_state) {
  global $user;
  
  $desc = db_escape_string($form_state['values']['searchname']);
  db_query('INSERT INTO {sopac_saved_searches} VALUES (0, ' . $user->uid . ', NOW(), "' . $desc . '", "' . $form_state['values']['uri'] . '")');

  $submsg = '<strong>»</strong> ' . t('You have saved this search.') . '<br /><strong>»</strong> <a href="' . $form_state['values']['uri'] . '">' . t('Return to your search') . '</a><br /><br />';
  drupal_set_message($submsg);

}


function sopac_update_locum_acct($op, &$edit, &$account) {
  
  $locum = new locum_client;
  
  // Make sure we're all legit on this account
  $cardnum = $account->profile_pref_cardnum;
  if (!$cardnum) { return 0; }
  $userinfo = $locum->get_patron_info($cardnum);
  $bcode_verify = sopac_bcode_isverified($account);
  if ($bcode_verify) { $account->bcode_verify = TRUE; } else { $account->bcode_verify = FALSE; }
  if ($userinfo['pnum']) { $account->valid_card = TRUE; } else { $account->valid_card = FALSE; }
  if (!$account->valid_card || !$bcode_verify) { return 0; }
  
  if ($edit['mail'] && $pnum) {
    // TODO update email. etc.
  }
}

/**
 * Creates and returns the barcode/patron card number verification form.  It also does the neccesary processing
 * If this function has just successfully processed a form result, then it will instead return a message indicating thus.
 *
 * @param string $cardnum Library patron barcode/card number
 * @return string Either the verification form or a confirmation of success.
 */
function sopac_bcode_verify_form() {
  
  $args = func_get_args();

  if (variable_get('sopac_require_cfg', 'one') == 'one') { 
    $req_flds = FALSE;
    $form_desc = t('Please correctly <strong>answer <u>one</u> of the following questions</strong>:');
  } else {
    $req_flds = TRUE;
        $form_desc = t('Please correctly <strong>answer <u>all</u> of the following questions</strong>:');
  }
  
  $form['sopac_card_verify'] = array(
    '#type' => 'fieldset',
    '#title' => t('Verify your Library Card Number'),
    '#description' => t($form_desc),
    '#collapsible' => FALSE,
    '#validate' => 'sopac_bcode_verify_form_validate',
  );
  
  if (variable_get('sopac_require_name', 1)) {
    $form['sopac_card_verify']['last_name'] = array(
      '#type' => 'textfield',
      '#title' => t('What is your last name?'),
      '#size' => 32,
      '#maxlength' => 128,
      '#required' => $req_flds,
      '#value' => $_POST['last_name'],
    );
  }
  
  if (variable_get('sopac_require_streetname', 1)) {
    $form['sopac_card_verify']['streetname'] = array(
      '#type' => 'textfield',
      '#title' => t('What is the name of the street you live on?'),
      '#size' => 24,
      '#maxlength' => 32,
      '#required' => $req_flds,
      '#value' => $_POST['streetname'],
    );
  }
  
  if (variable_get('sopac_require_tel', 1)) {
    $form['sopac_card_verify']['telephone'] = array(
      '#type' => 'textfield',
      '#title' => t('What is your telephone number?'),
      '#description' => t("Please provide your area code as well as your phone number, eg: 203-555-1234."),
      '#size' => 18,
      '#maxlength' => 24,
      '#required' => $req_flds,
      '#value' => $_POST['telephone'],
    );
  }
  
  $form['sopac_card_verify']['vfy_post'] = array('#type' => 'hidden', '#value' => '1');
  $form['sopac_card_verify']['uid'] = array('#type' => 'hidden', '#value' => $args[1]);
  $form['sopac_card_verify']['cardnum'] = array('#type' => 'hidden', '#value' => $args[2]);
  $form['sopac_card_verify']['vfy_submit'] = array('#type' => 'submit', '#value' => t('Verify!'));
  
  return $form;
}

function sopac_bcode_verify_form_validate($form, $form_state) {
  global $account;
  
  $locum = new locum_client;
  $cardnum = $form_state['values']['cardnum'];
  $uid = $form_state['values']['uid'];
  $userinfo = $locum->get_patron_info($cardnum);
  $numreq = 0;
  $correct = 0;
  $validated = FALSE;

  $req_cfg = variable_get('sopac_require_cfg', 'one');
  
  // Match the name given
  if (variable_get('sopac_require_name', 1)) {
    if (trim($form_state['values']['last_name'])) {
      $locum_name = ereg_replace("[^A-Za-z0-9 ]", "", trim(strtolower($userinfo['name'])));
      $sub_name = ereg_replace("[^A-Za-z0-9 ]", "", trim(strtolower($form_state['values']['last_name'])));
      if (preg_match('/\b' . $sub_name . '\b/i', $locum_name)) {
        $correct++;
      } else {
        $error[] = t('The last name you entered does not appear to match what we have on file.');
      }
    } else {
      $error[] = t('You did not provide a last name.');
    }
    $numreq++;
  }
  
  if (variable_get('sopac_require_streetname', 1)) {
    if (trim($form_state['values']['streetname'])) {
      $locum_addr = ereg_replace("[^A-Za-z ]", "", trim(strtolower($userinfo['address'])));
      $sub_addr = ereg_replace("[^A-Za-z ]", "", trim(strtolower($form_state['values']['streetname'])));
      $sub_addr_arr = explode(' ', $sub_addr);
      if (strlen($sub_addr_arr[0]) == 1 || $sub_addr_arr[0] == 'north' || $sub_addr_arr[0] == 'east' || $sub_addr_arr[0] == 'south' || $sub_addr_arr[0] == 'west') {
        $sub_addr = $sub_addr_arr[1];
      } else {
        $sub_addr = $sub_addr_arr[0];
      }
      if (preg_match('/\b' . $sub_addr . '\b/i', $locum_addr)) {
        $correct++;
      } else {
        $error[] = t('The street name you entered does not appear to match what we have on file.');
      }
    } else {
      $error[] = t('You did not provide a street name.');
    }
    $numreq++;
  }
  
  if (variable_get('sopac_require_tel', 1)) {
    if (trim($form_state['values']['telephone'])) {
      $locum_tel = ereg_replace("[^A-Za-z0-9 ]", "", trim(strtolower($userinfo['tel1'] . ' ' . $userinfo['tel2'])));
      $sub_tel = ereg_replace("[^A-Za-z0-9 ]", "", trim(strtolower($form_state['values']['telephone'])));
      if (preg_match('/\b' . $sub_tel . '\b/i', $locum_tel)) {
        $correct++;
      } else {
        $error[] = t('The telephone number you entered does not appear to match what we have on file.');
      }
    } else {
      $error[] = t('You did not provide a telephone number.');
    }
    $numreq++;
  }
  
  if ($req_cfg == 'one') {
    if ($correct > 0) { $validated = TRUE; }
  } else {
    if ($correct == $numreq) { $validated = TRUE; }
  }
  
  if (count($error) && !$validated) {
    foreach ($error as $errkey => $errmsg) {
      form_set_error($errkey, t($errmsg));
    }
  }
  
  if ($validated) {
    db_query("INSERT INTO {sopac_card_verify} VALUES ($uid, '$cardnum', 1, NOW())");
  }
  
}





