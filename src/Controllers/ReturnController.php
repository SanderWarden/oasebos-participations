<?php
declare(strict_types=1);
namespace Oasebos\Participations\Controllers;
final class ReturnController { public function register(): void { add_action('template_redirect',function(){ if(isset($_GET['oasebos_payment_return'])){ status_header(200); get_header(); echo do_shortcode('[oasebos_payment_return]'); get_footer(); exit; } }); } }
