<?php
/**
 * Plugin Name: ARI Community Manager
 * Description: Read-only community registry and dry run.
 * Version: 0.2.0
 */
defined('ABSPATH') || exit;
add_action('admin_menu', function(){add_management_page('ARI Community Manager','ARI Community Manager','manage_options','ari-community-manager',function(){
if(!current_user_can('manage_options'))return;
echo '<div class="wrap"><h1>ARI Community Manager — Dry Run</h1><p>Read only. No changes are applied.</p>';
$registry=['TELUS Digital'=>['Search Quality Rating','Ads Quality Rating','Internet Safety','Maps & Local','Media Search'],'Welo Data'=>['Search Quality Rating','Ads Quality Rating','AI Training & Evaluation'],'RWS TrainAI'=>['Search Quality Rating','AI Training & Evaluation'],'Meridial'=>['AI Training & Evaluation']];
try{$f=function_exists('WPF')?(WPF()->forum??null):null;if(!is_object($f)||!is_callable([$f,'get_forums']))throw new RuntimeException();$rows=$f->get_forums();if(!is_array($rows)||!$rows)throw new RuntimeException();foreach($rows as $r){if(!is_array($r)||!isset($r['forumid'],$r['title'],$r['parentid']))throw new RuntimeException();}
$find=function($title,$parent)use($rows){return array_values(array_filter($rows,function($r)use($title,$parent){return strcasecmp(trim($r['title']),$title)===0&&(int)$r['parentid']===$parent;}));};
$line=function($title,$action,$id=''){echo '<tr><td>'.esc_html($title).'</td><td>'.esc_html($action).'</td><td>'.esc_html((string)$id).'</td></tr>';};
echo '<table class="widefat"><thead><tr><th>Registry path</th><th>Proposed action</th><th>Existing ID</th></tr></thead><tbody>';
foreach($registry as $vendor=>$projects){$m=$find($vendor,0);$n=count($m);$id=$n===1?(int)$m[0]['forumid']:null;$line($vendor,$n>1?'Conflict':($n?'Keep':'Create category'),$id??'');foreach($projects as $p){$matches=$id===null?[]:$find($p,$id);$line($vendor.' / '.$p,$n>1?'Blocked: parent conflict':(count($matches)>1?'Conflict':($matches?'Keep':'Create forum')),count($matches)===1?$matches[0]['forumid']:'');}}
echo '</tbody></table><h2>Existing records (all preserved)</h2><table class="widefat"><thead><tr><th>Title</th><th>ID</th><th>Parent</th><th>Layout</th></tr></thead><tbody>';foreach($rows as $r){echo '<tr>';foreach(['title','forumid','parentid','cat_layout'] as $k)echo '<td>'.esc_html((string)($r[$k]??'')).'</td>';echo '</tr>';}echo '</tbody></table><p>Exact title and parent matching only. Renames, aliases, permissions and layout compatibility require review before any future write.</p>';
}catch(Throwable $e){echo '<p>Unable to validate forum records. No plan generated.</p>';}echo '</div>';});});
