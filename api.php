<?php
namespace KVSun\API;

use \shgysk8zer0\Core\{
	PDO,
	JSON_Response as Resp,
	URL,
	Headers,
	Console,
	UploadFile,
	Image,
	Listener
};
use \shgysk8zer0\DOM\{HTML, HTMLElement, RSS};
use \shgysk8zer0\Core_API\{Abstracts\HTTPStatusCodes as HTTP};
use \KVSun\KVSAPI\{Home, Category, Article};
use \shgysk8zer0\Login\{User};

use function \KVSun\Functions\{
	user_can,
	restore_login,
	email,
	get_role_id,
	get_categories,
	get_comments,
	delete_comments,
	user_update_form,
	make_cc_form,
	make_datalist,
	make_picture
};

use const \KVSun\Consts\{
	DEBUG,
	DOMAIN,
	ICONS,
	DB_CREDS,
	COMPONENTS,
	LOGO,
	LOGO_VECTOR,
	LOGO_SIZE,
	IMG_SIZES,
	IMG_FORMATS
};

if (in_array(PHP_SAPI, ['cli', 'cli-server'])) {
	require_once __DIR__ . DIRECTORY_SEPARATOR . 'autoloader.php';
}

$header  = Headers::getInstance();

if ($header->accept === 'application/json') {
	$resp = Resp::getInstance();
	if (array_key_exists('url', $_GET)) {
		$header->content_type = 'application/json';
		$url = new URL($_GET['url']);
		$path = explode('/', trim($url->path));
		$path = array_filter($path);
		$path = array_values($path);

		if (empty($path)) {
			// This would be a request for home
			// $categories = \KVSun\get_categories();
			$page = new Home(PDO::load(DB_CREDS), "$url", get_categories('url'));
		} elseif (count($path) === 1) {
			$page = new Category(PDO::load(DB_CREDS), "$url");
		} elseif (count($path) === 2) {
			$page = new Article(PDO::load(DB_CREDS), "$url");
			if (! $page->is_free and ! user_can('paidArticles')) {
				$resp->notify(
					'You must be a subscriber to view this content',
					'Please login to continue.',
					DOMAIN . ICONS['sign-in']
				)->showModal('#login-dialog')
				->send();
			}
		} else {
			$resp->notify(
				'Invalid request',
				"No content for {$url}",
				DOMAIN . ICONS['circle-slash']
			)->send();
		}

		exit(json_encode($page));
	} elseif (array_key_exists('form', $_REQUEST) and is_array($_REQUEST[$_REQUEST['form']])) {
		require_once COMPONENTS . 'handlers' . DIRECTORY_SEPARATOR . 'form.php';
	} elseif (array_key_exists('datalist', $_GET)) {
		switch($_GET['datalist']) {
			case 'categories':
				$pdo = PDO::load(DB_CREDS);
				$cats = $pdo('SELECT `name` FROM `categories`');
				$doc = new \DOMDocument();
				$doc->appendChild($doc->createElement('datalist'));
				$doc->documentElement->setAttribute('id', 'categories');
				foreach ($cats as $cat) {
					$item = $doc->documentElement->appendChild($doc->createElement('option'));
					$item->setAttribute('value', $cat->name);
				}

				$resp->append('body', $doc->saveHTML($doc->documentElement));
				break;

			case 'author_list':
				$pdo = PDO::load(DB_CREDS);
				$stm = $pdo->prepare('SELECT `name`
					FROM `user_data`
					JOIN `subscribers` ON `subscribers`.`id` = `user_data`.`id`
					WHERE `subscribers`.`status` <= :role;'
				);
				$stm->role = get_role_id('freelancer');
				$authors = $stm->execute()->getResults();
				$authors = array_map(function(\stdClass $author): String
				{
					return $author->name;
				}, $authors);

				$datalist = make_datalist('author_list', $authors);

				$resp->append('body', $datalist)->send();
				break;

			default:
				trigger_error("Request for unhandled list: {$_GET['list']}");
				header('Content-Type: application/json');
				exit('{}');
		}
	} elseif (array_key_exists('load_menu', $_GET)) {
		$menu = COMPONENTS . 'menus' . DIRECTORY_SEPARATOR . $_GET['load_menu'] . '.html';
		if (@file_exists($menu)) {
			$resp->append('body', file_get_contents($menu));
		} else {
			$resp->notify('Request for menu', $_GET['load_menu']);
		}
	} elseif (array_key_exists('load_form', $_REQUEST)) {
		switch($_REQUEST['load_form']) {
			case 'forgot_password':
				$doc = new HTML;
				$dialog = $doc->body->append('dialog');
				$dialog->id = 'forgot_password_dialog';
				$dialog->append('button', null, [
					'data-delete' => "#{$dialog->id}",
				]);
				$dialog->append('hr');
				$dialog->importHTMLFile(COMPONENTS . DIRECTORY_SEPARATOR . 'forms' . DIRECTORY_SEPARATOR . 'forgot_password.html');
				$resp->append('body', $dialog);
				$resp->showModal("#{$dialog->id}");
				break;

			case 'update-user':
				$user = User::load(DB_CREDS);
				if (!isset($user->status)) {
					$resp->notify('You must login for that', 'Cannot update data before logging in.');
					$resp->showModal('#login-dialog');
					$resp->send();
				}
				$dialog = user_update_form($user);
				$resp->append('body', "$dialog");
				$resp->showModal("#{$dialog->id}");
				$resp->animate("#{$dialog->id}", [
					['transform' => 'translateX(50vw) translateY(50vh) scale(0.1)'],
					['transform' => 'none'],
				], [
					'duration' => 300,
				]);
				break;

			case 'ccform':
				$user = User::load(DB_CREDS);
				if (!isset($user->status)) {
					$resp->notify(
						'You must be logged in for that',
						'You will need to login to have an account to update'
					);

					$resp->showModal('#login-dialog');
					$resp->send();
				} elseif ($user->hasPermission('paidArticles') or true) {
					$resp->notify(
						'You do not need to subscribe',
						"Your subscription has not yet expired"
					)->send();
				}

				$dom = new HTML();
				$dialog = $dom->body->append('dialog', null, [
					'id' => 'ccform-dialog'
				]);
				$dialog->append('button', null, [
					'type' => 'button',
					'data-delete' => "#{$dialog->id}",
				]);
				make_cc_form($dialog);
				$resp->append('body', $dialog);
				$resp->showModal("#{$dialog->id}");
				break;

			case 'moderate':
				if (user_can('moderateComments')) {
					try {
						$comments = get_comments();
						$doc = new HTML;
						$dialog = $doc->body->append('dialog', null, [
							'id' => 'comment-moderator',
						]);
						$dialog->append('button', null, [
							'data-delete' => "#{$dialog->id}",
						]);
						$dialog->append('hr');
						$form = $dialog->append('form', null, [
							'name' => 'comment-moderator-form',
							'action' => DOMAIN . 'api.php',
							'method' => 'POST',
						]);
						$table = $form->append('table', null, [
							'border' => 1,
						]);
						$form->append('button', 'Update', [
							'type' => 'submit',
						]);
						$thead = $table->append('thead');
						$tbody = $table->append('tbody');
						$tfoot = $table->append('tfoot');
						$tr = $thead->append('tr');
						foreach ([
							'User',
							'Date',
							'Category',
							'Article',
							'Comment',
							'Approved',
							'Delete comment'
						] as $key) {
							$tr->append('th', $key);
						}
						foreach ($comments as $comment) {
							$tr = $tbody->append('tr', null, [
								'id' => "moderate-comments-row-{$comment->ID}",
							]);
							$tr->append('td')->append('a', $comment->name, [
								'href' => "mailto:{$comment->email}",
							]);
							$tr->append('td', (new \DateTime($comment->created))->format('D, M jS, Y @ g:i:s A'));
							$tr->append('td', $comment->category);
							$tr->append('td')->append('a', $comment->Article, [
								'href' => DOMAIN . "{$comment->catURL}/{$comment->postURL}#comment-{$comment->ID}",
								'target' => '_blank',
							]);
							$tr->append('td')->append('blockquote', $comment->comment);
							$approved = $tr->append('td');
							$approved_y = $approved->append('label', 'Yes')->append('input', null, [
								'type' => 'radio',
								'name' => "{$form->name}[approved][{$comment->ID}]",
								'value' => '1',
							]);
							$approved_n = $approved->append('label', 'No')->append('input', null, [
								'type' => 'radio',
								'name' => "{$form->name}[approved][{$comment->ID}]",
								'value' => '0',
							]);
							$tr->append('td')->append('button', 'X', [
								'data-request' => "delete-comment={$comment->ID}",
								'data-confirm' => 'Are you sure you want to delete this comment?',
							]);
							if ($comment->approved) {
								$approved_y->checked = null;
							} else {
								$approved_n->checked = null;
							}
						}
						$resp->append('body', "$dialog");
						$resp->showModal("#{$dialog->id}");
					} catch (\Throwable $e) {
						trigger_error($e->getMessage());
						$resp->notify('There was an error', $e->getMessage());
					}
				}
				break;

			default:
			// All HTML forms in forms/ should be considered publicly available
				if (@file_exists("./components/forms/{$_REQUEST['load_form']}.html")) {
					$form = file_get_contents("./components/forms/{$_REQUEST['load_form']}.html");
					$dom = new HTML();
					$dialog = $dom->body->append('dialog', null, [
						'id' => "{$_REQUEST['load_form']}-dialog",
					]);
					$dialog->append('button', null, [
						'type'        => 'button',
						'data-delete' => "#{$dialog->id}",
					]);
					$dialog->append('br');
					$dialog->importHTML($form);
					$resp->append('body', "{$dialog}");
					$resp->showModal("#{$dialog->id}");
					$resp->send();
				} else {
					$resp->notify('An error occured', "Request made for unknown form.");
				}
		}
	} elseif(array_key_exists('upload', $_FILES)) {
		if (! user_can('uploadMedia')) {
			trigger_error('Unauthorized upload attempted');
			$resp->notify(
				'Unauthorized',
				'You are not authorized to upload files',
				ICONS['alert']
			)->remove('main > *')->remove('#admin_menu')->send();
		}
		try {
			$dom = new HTML();
			$pdo = PDO::load(DB_CREDS);
			$user = restore_login();
			$pdo->beginTransaction();
			try {
				$img_stm = $pdo->prepare(
					'INSERT INTO `images` (
						`path`,
						`fileFormat`,
						`contentSize`,
						`height`,
						`width`,
						`uploadedBy`
					) VALUES (
						:path,
						:format,
						:size,
						:height,
						:width,
						:uploadedBy
					);'
				);
				$srcset_stm = $pdo->prepare(
					'INSERT INTO `srcset` (
						`parentID`,
						`path`,
						`width`,
						`height`,
						`format`,
						`filesize`
					) VALUES (
						:parent,
						:path,
						:width,
						:height,
						:format,
						:filesize
					);'
				);
				$imgs = Image::responsiveImagesFromUpload(
					'upload',
					['images', 'uploads', date('Y'), date('m')],
					IMG_SIZES,
					IMG_FORMATS
				);
				$parent = array_reverse($imgs['image/jpeg']);
				$parent = (object) end($parent);
				$img_stm->execute([
					'path'       => $parent->path,
					'format'     => $parent->type,
					'size'       => $parent->size,
					'height'     => $parent->height,
					'width'      => $parent->width,
					'uploadedBy' => $user->id,
				]);
				if (intval($img_stm->errorCode()) !== 0) {
					throw new \Exception(
						'SQL Error: '. join(PHP_EOL, $img_stm->errorInfo())
					);
				}
				$parent_id = $pdo->lastInsertId();
				foreach ($imgs as $format) {
					foreach ($format as $img) {
						$img = (object) $img;
						$srcset_stm->execute([
							'parent'   => $parent_id,
							'path'     => $img->path,
							'width'    => $img->width,
							'height'   => $img->height,
							'format'   => $img->type,
							'filesize' => $img->size,
						]);
						if (intval($srcset_stm->errorCode()) !== 0) {
							throw new \Exception(
								'SQL Error: '. join(PHP_EOL, $srcset_stm->errorInfo())
							);
						}
					}
				}
				Console::table($pdo('SELECT * FROM `srcset`;'));
				Console::info($imgs);
				$pdo->rollBack();
			} catch (\Throwable $e) {
				$pdo->rollBack();
				$resp->notify(
					'Error uploading image',
					$e->getMessage(),
					ICONS['bug']
				)->send();
			}

			$picture = make_picture($imgs, $dom->body, '{PHOTOGRAPHER}', '{CUTLINE}');
			exit($picture);
		} catch (\Throwable $e) {
			trigger_error($e->getMessage());
			$resp->notify(
				'There was an error uploading the image',
				$e->getMessage(),
				ICONS['bug']
			);
		}
	} elseif (array_key_exists('action', $_REQUEST)) {
		switch($_REQUEST['action']) {
			case 'logout':
				Listener::logout(restore_login());
				break;

			case 'debug':
				if (user_can('debug')) {
					Console::info([
						'memory' => (memory_get_peak_usage(true) / 1024) . ' kb',
						'resources' => [
							'files'          => get_included_files(),
							'path'           => explode(PATH_SEPARATOR, get_include_path()),
							'functions'      => get_defined_functions(true)['user'],
							'constansts'     => get_defined_constants(true)['user'],
							'classes' => [
								'classes'    => get_declared_classes(),
								'interfaces' => get_declared_interfaces(),
								'traits'     => get_declared_traits(),
							],
						],
						'globals' => [
							'_SERVER'  => $_SERVER,
							'_REQUEST' => $_REQUEST,
							'_SESSION' => $_SESSION ?? [],
							'_COOKIE'  => $_COOKIE,
						],
					]);
				}
				break;
		}
	} elseif (array_key_exists('delete-comment', $_GET)) {
		if (! user_can('moderateComments')) {
			$resp->notify(
				"I'm afraid I can't let you do that, Dave",
				'You are not authorized to moderate comments.',
				ICONS['alert']
			);
		} elseif (delete_comments($_GET['delete-comment'])) {
			$resp->notify('Comment deleted');
			$resp->remove("#moderate-comments-row-{$_GET['delete-comment']}");
		} else {
			$resp->notify(
				'Unable to delete comment',
				'Check error log.',
				ICONS['bug']
			);
		}
	} else {
		$resp->notify(
			'Invalid request',
			'Try reloading or contact us to report this error',
			DOMAIN . LOGO
		);
	}
	if (user_can('debug') or DEBUG) {
		Console::getInstance()->sendLogHeader();
	}
	$resp->send();
	exit();
}  elseif(array_key_exists('url', $_GET)) {
	$url = new URL($_GET['url']);
	if ($url->host === $_SERVER['SERVER_NAME']) {
		$header->location = "{$url}";
		http_response_code(HTTP::SEE_OTHER);
	} else {
		http_response_code(HTTP::BAD_REQUEST);
	}
} else {
	http_response_code(HTTP::BAD_REQUEST);
	$header->content_type = 'application/json';
	exit('{}');
}
