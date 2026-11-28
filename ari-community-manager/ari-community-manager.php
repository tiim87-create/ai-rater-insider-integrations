<?php
/**
 * Plugin Name: ARI Community Manager
 * Description: Read-only forum structure diagnostics.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
add_action('admin_menu', function () {
 add_management_page('ARI Community Manager','ARI Community Manager','manage_options','ari-community-manager',function(){
 if(!current_user_can('manage_options')) return;
 echo '<div class="wrap"><h1>ARI Community Manager — Read-only diagnostics</h1>';
 echo '<p>No forum records, settings or user data are changed.</p>';
 $f = function_exists('WPF') ? (WPF()->forum ?? null) : null;
 if(!is_object($f)){echo '<p>Forum API unavailable.</p></div>';return;}
 foreach(['get_forums','add','edit'] as $m){
 echo '<p>'.esc_html($m).': '.(is_callable([$f,$m])?'Available':'Unavailable').'</p>';
 if(is_callable([$f,$m])){ $r=new ReflectionMethod($f,$m); echo '<p>Parameters: '.esc_html(implode(', ',array_map(function($p){return $p->getName();},$r->getParameters()))).'</p>'; }
 }
 if(is_callable([$f,'get_forums'])){
 try { $rows=$f->get_forums(); if(is_array($rows)){echo '<table class="widefat"><thead><tr><th>ID</th><th>Title</th><th>Parent</th><th>Layout</th></tr></thead><tbody>';foreach($rows as $row){if(!is_array($row))continue;echo '<tr>';foreach(['forumid','title','parentid','cat_layout'] as $key)echo '<td>'.esc_html((string)($row[$key]??'')).'</td>';echo '</tr>';}echo '</tbody></table>';}else echo '<p>Unexpected result type.</p>'; } catch(Throwable $e){echo '<p>Read operation failed.</p>';}
 }
 echo '</div>';
 });
});
