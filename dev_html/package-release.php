<?php
/*
   +----------------------------------------------------------------------+
   | PEAR Web site version 1.0                                            |
   +----------------------------------------------------------------------+
   | Copyright (c) 2001-2005 The PHP Group                                |
   +----------------------------------------------------------------------+
   | This source file is subject to version 2.02 of the PHP license,      |
   | that is bundled with this package in the file LICENSE, and is        |
   | available at through the world-wide-web at                           |
   | http://www.php.net/license/2_02.txt.                                 |
   | If you did not receive a copy of the PHP license and are unable to   |
   | obtain it through the world-wide-web, please send a note to          |
   | license@php.net so we can mail you a copy immediately.               |
   +----------------------------------------------------------------------+
   | Authors: Pierre Joye <pierre@php.net>                                |
   +----------------------------------------------------------------------+
   $Id: release-upload.php -1   $
*/



define('HTML_FORM_MAX_FILE_SIZE', 16 * 1024 * 1024); // 16 MB
require_once 'HTML/Form.php';
function checkUser($user, $pacid = null)
{
    global $dbh;
    $add = ($pacid) ? 'AND p.id = ' . $dbh->quoteSmart($pacid) : '';
    // It's a lead or user of the package
    $query = "SELECT m.handle
              FROM packages p, maintains m
              WHERE
                 m.handle = ? AND
                 p.id = m.package $add AND
                 (m.role IN ('lead', 'developer'))";
    $res = $dbh->getOne($query, array($user));
    if ($res !== null) {
        return true;
    }
    // Try to see if the user is an admin
    $res = user::isAdmin($user);
    return ($res === true);
}


$display_upload_form         = true;
$display_verification = false;
$success              = false;
$errors               = array();

PEAR::pushErrorHandling(PEAR_ERROR_RETURN);

do {
    if (isset($_POST['upload'])) {
        // Upload Button

        include_once 'HTTP/Upload.php';
        $upload_obj = new HTTP_Upload('en');
        $file = $upload_obj->getFiles('distfile');
        if (PEAR::isError($file)) {
            $errors[] = $file->getMessage();
            break;
        }

        if ($file->isValid()) {
            $file->setName('uniq', 'pear-');
            $file->setValidExtensions('tgz', 'accept');
            $tmpfile = $file->moveTo(PEAR_UPLOAD_TMPDIR);
            if (PEAR::isError($tmpfile)) {
                $errors[] = $tmpfile->getMessage();
                break;
            }
            $tmpsize = $file->getProp('size');
        } elseif ($file->isMissing()) {
            $errors[] = 'No file has been uploaded.';
            break;
        } elseif ($file->isError()) {
            $errors[] = $file->errorMsg();
            break;
        }
	include_once 'PEAR/PackageFile.php';
	include_once 'PEAR/Config.php';

	$config = PEAR_Config::singleton();
	$pkg = new PEAR_PackageFile($config);
	$info = $pkg->fromTgzFile(PEAR_UPLOAD_TMPDIR . '/' . $tmpfile, PEAR_VALIDATE_NORMAL);
	$errors = $warnings = array();

	if (PEAR::isError($info)) {
		if (is_array($info->getUserInfo())) {
			foreach ($info->getUserInfo() as $err) {
				if ($err['level'] == 'error') {
					$errors[] = $err['message'];
				} else {
					$warnings[] = $err['message'];
				}
			}
		}
		$errors[] = $info->getMessage();
		break;
	}

	if (version_compare($info->getPackageXmlVersion(), '2.0', '<')) {
		$errors[] = 'package.xml v1 format is not supported anymore, please update your package.xml to 2.0. ';
		break; 
	}
        $display_upload_form = false;
        $display_verification = true;

    } elseif (isset($_POST['verify'])) {
        // Verify Button

        $distfile = PEAR_UPLOAD_TMPDIR . '/' . basename($_POST['distfile']);
        if (!@is_file($distfile)) {
            $errors[] = 'No verified file found.';
            break;
        }

        include_once 'PEAR/Common.php';
        $util =& new PEAR_Common;

        $config = PEAR_Config::singleton();
        $pkg = new PEAR_PackageFile($config);
        $info = $pkg->fromTgzFile($distfile, PEAR_VALIDATE_NORMAL);
        if (PEAR::isError($info)) {
            if (is_array($info->getUserInfo())) {
                foreach ($info->getUserInfo() as $err) {
                    $errors[] = $err['message'];
                }
                $errors[] = $info->getMessage();
            }
            break;
        } else {
            $pacid = package::info($info->getPackage(), 'id');
            if (PEAR::isError($pacid)) {
                $errors[] = $pacid->getMessage();
                break;
            }
            if (!user::isAdmin($auth_user->handle) &&
                !user::maintains($auth_user->handle, $pacid, 'lead')) {
                $errors[] = 'You don\'t have permissions to upload this release.';
                break;
            }
            $license = $info->getLicense();
            if (is_array($license)) {
                $license = $license['_content'];
            }
            $e = package::updateInfo($pacid,
                    array(
                        'summary'     => $info->getSummary(),
                        'description' => $info->getDescription(),
                        'license'     => $license,
                    ));
            if (PEAR::isError($e)) {
                $errors[] = $e->getMessage();
                break;
            }
            $users = array();
            foreach ($info->getMaintainers() as $user) {
                $users[strtolower($user['handle'])] = array(
                                                        'role'   => $user['role'],
                                                        'active' => !isset($user['active']) ||
                                                            $user['active'] == 'yes',
                                                      );
            }
            $e = maintainer::updateAll($pacid, $users);
            if (PEAR::isError($e)) {
                $errors[] = $e->getMessage();
                break;
            }
            $pear_rest->savePackageMaintainerREST($info->getPackage());
            $file = release::upload($info->getPackage(), $info->getVersion(),
                                    $info->getState(), $info->getNotes(),
                                    $distfile, md5_file($distfile));
        }

        if (PEAR::isError($file)) {
            $ui = $file->getUserInfo();
            $errors[] = 'Error while uploading package: ' .
                         $file->getMessage() . ($ui ? " ($ui)" : '');
            break;
        }
        @unlink($distfile);

        PEAR::pushErrorHandling(PEAR_ERROR_CALLBACK, 'report_warning');
        if (is_a($info, 'PEAR_PackageFile_v1') || is_a($info, 'PEAR_PackageFile_v2')) {
            release::promote_v2($info, $file);
        } else {
            release::promote($info, $file);
        }
        PEAR::popErrorHandling();

        $success              = true;
        $display_upload_form         = true;
        $display_verification = false;

    } elseif (isset($cancel)) {
        // Cancel Button

        $distfile = PEAR_UPLOAD_TMPDIR . '/' . basename($distfile);
        if (@is_file($distfile)) {
            @unlink($distfile);
        }

        $display_upload_form         = true;
        $display_verification = false;
    }
} while (false);

PEAR::popErrorHandling();


if ($display_upload_form) {
    $title = 'Upload New Release';
//    response_header($title);

    // Remove that code when release-upload also create new packages
    if (!checkUser($auth_user->handle)) {
        $errors[] = 'You are not registered as lead developer for any packages.';
    }

    if ($success) {
        if (is_array($info)) {
            report_success('Version ' . $info['version'] . ' of '
                           . $info['package'] . ' has been successfully released, '
                           . 'and its promotion cycle has started.');

        } else {
            report_success('Version ' . $info->getVersion() . ' of '
                           . $info->getPackage() . ' has been successfully released, '
                           . 'and its promotion cycle has started.');
        }

    } else {
        report_error($errors);
    }

    $form =& new HTML_Form('/package-release.php', 'post', '', '',
            'multipart/form-data');
    $form->addFile('distfile',
            '<label for="f" accesskey="i">D<span class="accesskey">i</span>'
            . 'stribution File</label>',
            HTML_FORM_MAX_FILE_SIZE, 40, '', 'id="f"');
    $form->addSubmit('upload', 'Upload!');
    $form->display('class="form-holder" cellspacing="1"',
            'Upload', 'class="form-caption"');
}

if ($display_verification) {
    include_once 'PEAR/PackageFile.php';
    include_once 'PEAR/Config.php';

    //response_header('Upload New Release :: Verify');

    $config = &PEAR_Config::singleton();
    $pkg = new PEAR_PackageFile($config);
    $info = $pkg->fromTgzFile(PEAR_UPLOAD_TMPDIR . '/' . $tmpfile, PEAR_VALIDATE_NORMAL);
    $errors = $warnings = array();
    if (PEAR::isError($info)) {
        if (is_array($info->getUserInfo())) {
            foreach ($info->getUserInfo() as $err) {
                if ($err['level'] == 'error') {
                    $errors[] = $err['message'];
                } else {
                    $warnings[] = $err['message'];
                }
            }
        }
        $errors[] = $info->getMessage();
    }

    if ($info->getChannel() != 'pecl.php.net') {
        $warnings[] = 'Your package uses package.xml 1.0.  With the release of PEAR 1.4.0 stable, '
            . 'PECL packages will require package.xml 2.0 and channel name "pecl.php.net"';
    }
    // this next switch may never be used, but is here in case it turns out to be a good move
    switch ($info->getPackageType()) {
        case 'extsrc' :
            $type = 'Extension Source package';
        break;
        case 'extbin' :
            $type = 'Extension Binary package';
        break;
        case 'php' :
            $type = 'PHP package';
            if ($info->getPackagexmlVersion() == '1.0') {
                $warnings[] = 'package.xml 1.0 cannot distinguish between different release ' .
                    'types';
            }
        break;
        default :
            $errors[] = 'Release type ' . $info->getPackageType() . ' is not ' .
                'supported at pecl.php.net, only Extension releases are supported.  ' .
                'pear.php.net supports php packages';
    }
    report_error($errors, 'errors','ERRORS:<br />'
                 . 'You must correct your package.xml file:');
    report_error($warnings, 'warnings', 'RECOMMENDATIONS:<br />'
                 . 'You may want to correct your package.xml file:');
    $form =& new HTML_Form($script_name, 'post');
    $form->addPlaintext('Package:', $info->getPackage());
    $form->addPlaintext('Version:', $info->getVersion());
    $form->addPlaintext('Summary:', htmlspecialchars($info->getSummary(), ENT_QUOTES));
    $form->addPlaintext('Description:', nl2br(htmlspecialchars($info->getDescription(), ENT_QUOTES)));
    $form->addPlaintext('Release State:', $info->getState());
    $form->addPlaintext('Release Date:', $info->getDate());
    $form->addPlaintext('Release Notes:', nl2br(htmlspecialchars($info->getNotes(), ENT_QUOTES)));
    $form->addPlaintext('Package Type:', $type);

    // Don't show the next step button when errors found
    if (!count($errors)) {
        $form->addSubmit('verify', 'Verify Release');
    }

    $form->addSubmit('cancel', 'Cancel');
    $form->addHidden('distfile', $tmpfile);
    if (0)
    $form->display('class="form-holder" cellspacing="1"',
            'Please verify that the following release information is correct:',
            'class="form-caption"');
}

$data = array(
    'display_upload_form' => $display_upload_form,
    'display_verification' => $display_verification,
);

$page = new PeclPage('/developer/page_developer.html');
$page->title = $title;
$page->jquery = true;
$page->addData($data);

$page->addJsSrc('/js/jquery.fileupload.js');
$page->addJsSrc('/js/jquery.fileupload-ui.js');
$page->addJsSrc('/js/jquery.iframe-transport.js');
$page->addJsSrc('/js/release-upload.js');
$page->addStyle('/js/jquery.fileupload-ui.css');
$page->addJsSrc('https://ajax.aspnetcdn.com/ajax/jquery.templates/beta1/jquery.tmpl.min.js');
$page->setTemplate(PECL_TEMPLATE_DIR . '/developer/package-release.html');
$page->render();

echo $page->html;