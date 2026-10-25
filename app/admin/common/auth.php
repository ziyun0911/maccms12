<?php
return array (
  1 => 
  array (
    'name' => lang('menu/index'),
    'icon' => 'xe625',
    'sub' => 
    array (
      11 => 
      array (
        'show' => 1,
        'name' => lang('menu/welcome'),
        'controller' => 'index',
        'action' => 'welcome',
      ),
      12 => 
      array (
        'show' => 1,
        'name' => lang('menu/quickmenu'),
        'controller' => 'index',
        'action' => 'quickmenu',
      ),
      671 => 
      array (
        'show' => 1,
        'name' => lang('menu/analytics'),
        'controller' => 'analytics',
        'action' => 'index',
      ),
      67101 => 
      array (
        'show' => 0,
        'name' => lang('admin/analytics/auth_trend'),
        'controller' => 'analytics',
        'action' => 'trend',
      ),
      67102 => 
      array (
        'show' => 0,
        'name' => lang('admin/analytics/auth_export'),
        'controller' => 'analytics',
        'action' => 'export',
      ),
      6711 => 
      array (
        'show' => 0,
        'name' => lang('admin/system/configanalytics'),
        'controller' => 'system',
        'action' => 'configanalytics',
      ),
      1001 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/index_iframe'),
        'controller' => 'index',
        'action' => 'iframe',
      ),
      1002 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/index_clear'),
        'controller' => 'index',
        'action' => 'clear',
      ),
      1003 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/index_unlocked'),
        'controller' => 'index',
        'action' => 'unlocked',
      ),
      1004 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/index_select'),
        'controller' => 'index',
        'action' => 'select',
      ),
      1005 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/upload_upload'),
        'controller' => 'upload',
        'action' => 'upload',
      ),
    ),
  ),
  2 => 
  array (
    'name' => lang('menu/vod'),
    'icon' => 'xe639',
    'sub' => 
    array (
      44 => 
      array (
        'show' => 1,
        'name' => lang('menu/vod_data'),
        'controller' => 'vod',
        'action' => 'data',
      ),
      45 => 
      array (
        'show' => 1,
        'name' => lang('menu/vod_add'),
        'controller' => 'vod',
        'action' => 'info',
      ),
      46 => 
      array (
        'show' => 1,
        'name' => lang('menu/vod_data_url_empty'),
        'controller' => 'vod',
        'action' => 'data',
        'param' => 'url=1',
      ),
      47 => 
      array (
        'show' => 1,
        'name' => lang('menu/vod_data_lock'),
        'controller' => 'vod',
        'action' => 'data',
        'param' => 'lock=1',
      ),
      48 => 
      array (
        'show' => 1,
        'name' => lang('menu/vod_data_audit'),
        'controller' => 'vod',
        'action' => 'data',
        'param' => 'status=0',
      ),
      483 => 
      array (
        'show' => 1,
        'name' => lang('menu/vod_data_rejected'),
        'controller' => 'vod',
        'action' => 'data',
        'param' => 'status=2',
      ),
      485 => 
      array (
        'show' => 1,
        'name' => lang('menu/vod_data_draft'),
        'controller' => 'vod',
        'action' => 'data',
        'param' => 'status=3',
      ),
      486 => 
      array (
        'show' => 1,
        'name' => lang('menu/vod_data_scheduled'),
        'controller' => 'vod',
        'action' => 'data',
        'param' => 'status=4',
      ),
      481 => 
      array (
        'show' => 1,
        'name' => lang('menu/vod_data_points'),
        'controller' => 'vod',
        'action' => 'data',
        'param' => 'points=1',
      ),
      482 => 
      array (
        'show' => 1,
        'name' => lang('menu/vod_data_plot'),
        'controller' => 'vod',
        'action' => 'data',
        'param' => 'plot=1',
      ),
      491 => 
      array (
        'show' => 1,
        'name' => lang('menu/vod_repeat'),
        'controller' => 'vod',
        'action' => 'data',
        'param' => 'repeat=1',
      ),
      49 => 
      array (
        'show' => 1,
        'name' => lang('menu/vod_batch'),
        'controller' => 'vod',
        'action' => 'batch',
      ),
      484 => 
      array (
        'show' => 1,
        'name' => lang('menu/vod_audit_rule'),
        'controller' => 'vodauditrule',
        'action' => 'index',
      ),
      48401 => 
      array (
        'show' => 0,
        'name' => lang('menu/vod_audit_rule_info'),
        'controller' => 'vodauditrule',
        'action' => 'info',
      ),
      48402 => 
      array (
        'show' => 0,
        'name' => lang('menu/vod_audit_rule_del'),
        'controller' => 'vodauditrule',
        'action' => 'del',
      ),
      48403 => 
      array (
        'show' => 0,
        'name' => lang('menu/vod_audit_rule_field'),
        'controller' => 'vodauditrule',
        'action' => 'field',
      ),
      4401 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/vod_info'),
        'controller' => 'vod',
        'action' => 'info',
      ),
      4402 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/vod_del'),
        'controller' => 'vod',
        'action' => 'del',
      ),
      4403 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/vod_field'),
        'controller' => 'vod',
        'action' => 'field',
      ),
      4404 => 
      array (
        'show' => 0,
        'name' => lang('menu/vod_audit_batch'),
        'controller' => 'vod',
        'action' => 'audit',
      ),
      4405 => 
      array (
        'show' => 0,
        'name' => lang('menu/vod_audit_select_status'),
        'controller' => 'vod',
        'action' => 'selectStatus',
      ),
      42 => 
      array (
        'show' => 1,
        'name' => lang('menu/player'),
        'controller' => 'vodplayer',
        'action' => 'index',
      ),
      4201 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/vodplayer_info'),
        'controller' => 'vodplayer',
        'action' => 'info',
      ),
      4202 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/vodplayer_del'),
        'controller' => 'vodplayer',
        'action' => 'del',
      ),
      4203 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/vodplayer_field'),
        'controller' => 'vodplayer',
        'action' => 'field',
      ),
      96 => 
      array (
        'show' => 1,
        'name' => lang('admin/batchplayer/title'),
        'controller' => 'batch_player',
        'action' => 'index',
      ),
      9601 => 
      array (
        'show' => 0,
        'name' => lang('admin/batchplayer/batch_status_menu'),
        'controller' => 'batch_player',
        'action' => 'batchStatus',
      ),
      9602 => 
      array (
        'show' => 0,
        'name' => lang('admin/batchplayer/batch_del_menu'),
        'controller' => 'batch_player',
        'action' => 'batchDel',
      ),
      41 => 
      array (
        'show' => 1,
        'name' => lang('menu/server'),
        'controller' => 'vodserver',
        'action' => 'index',
      ),
      4101 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/vodserver_info'),
        'controller' => 'vodserver',
        'action' => 'info',
      ),
      4102 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/vodserver_del'),
        'controller' => 'vodserver',
        'action' => 'del',
      ),
      4103 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/vodserver_field'),
        'controller' => 'vodserver',
        'action' => 'field',
      ),
      43 => 
      array (
        'show' => 1,
        'name' => lang('menu/downer'),
        'controller' => 'voddowner',
        'action' => 'index',
      ),
      4301 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/voddowner_info'),
        'controller' => 'voddowner',
        'action' => 'info',
      ),
      4302 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/voddowner_del'),
        'controller' => 'voddowner',
        'action' => 'del',
      ),
      4303 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/voddowner_field'),
        'controller' => 'voddowner',
        'action' => 'field',
      ),
      497 => 
      array (
        'show' => 1,
        'name' => lang('menu/vodplayfail'),
        'controller' => 'vodplayfail',
        'action' => 'index',
      ),
      4971 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/vodplayfail_del'),
        'controller' => 'vodplayfail',
        'action' => 'del',
      ),
    ),
  ),
  3 => 
  array (
    'name' => lang('menu/manga'),
    'icon' => 'xe616',
    'sub' => 
    array (
      131 => 
      array (
        'show' => 1,
        'name' => lang('menu/manga_data'),
        'controller' => 'manga',
        'action' => 'data',
      ),
      132 => 
      array (
        'show' => 1,
        'name' => lang('menu/manga_add'),
        'controller' => 'manga',
        'action' => 'info',
      ),
      133 => 
      array (
        'show' => 1,
        'name' => lang('menu/manga_data_lock'),
        'controller' => 'manga',
        'action' => 'data',
        'param' => 'lock=1',
      ),
      134 => 
      array (
        'show' => 1,
        'name' => lang('menu/manga_data_audit'),
        'controller' => 'manga',
        'action' => 'data',
        'param' => 'status=0',
      ),
      1392 => 
      array (
        'show' => 1,
        'name' => lang('menu/manga_data_url_empty'),
        'controller' => 'manga',
        'action' => 'data',
        'param' => 'url=1',
      ),
      1393 => 
      array (
        'show' => 1,
        'name' => lang('menu/manga_data_points'),
        'controller' => 'manga',
        'action' => 'data',
        'param' => 'points=1',
      ),
      1391 => 
      array (
        'show' => 1,
        'name' => lang('menu/manga_repeat'),
        'controller' => 'manga',
        'action' => 'data',
        'param' => 'repeat=1',
      ),
      139 => 
      array (
        'show' => 1,
        'name' => lang('menu/manga_batch'),
        'controller' => 'manga',
        'action' => 'batch',
      ),
      13101 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/manga_info'),
        'controller' => 'manga',
        'action' => 'info',
      ),
      13102 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/manga_del'),
        'controller' => 'manga',
        'action' => 'del',
      ),
      13103 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/manga_field'),
        'controller' => 'manga',
        'action' => 'field',
      ),
    ),
  ),
  4 => 
  array (
    'name' => lang('menu/art'),
    'icon' => 'xe616',
    'sub' => 
    array (
      51 => 
      array (
        'show' => 1,
        'name' => lang('menu/art_data'),
        'controller' => 'art',
        'action' => 'data',
      ),
      52 => 
      array (
        'show' => 1,
        'name' => lang('menu/art_add'),
        'controller' => 'art',
        'action' => 'info',
      ),
      53 => 
      array (
        'show' => 1,
        'name' => lang('menu/art_data_lock'),
        'controller' => 'art',
        'action' => 'data',
        'param' => 'lock=1',
      ),
      54 => 
      array (
        'show' => 1,
        'name' => lang('menu/art_data_audit'),
        'controller' => 'art',
        'action' => 'data',
        'param' => 'status=0',
      ),
      591 => 
      array (
        'show' => 1,
        'name' => lang('menu/art_repeat'),
        'controller' => 'art',
        'action' => 'data',
        'param' => 'repeat=1',
      ),
      59 => 
      array (
        'show' => 1,
        'name' => lang('menu/art_batch'),
        'controller' => 'art',
        'action' => 'batch',
      ),
      5101 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/art_info'),
        'controller' => 'art',
        'action' => 'info',
      ),
      5102 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/art_del'),
        'controller' => 'art',
        'action' => 'del',
      ),
      5103 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/art_field'),
        'controller' => 'art',
        'action' => 'field',
      ),
    ),
  ),
  5 => 
  array (
    'name' => lang('menu/website'),
    'icon' => 'xe616',
    'sub' => 
    array (
      121 => 
      array (
        'show' => 1,
        'name' => lang('menu/website_data'),
        'controller' => 'website',
        'action' => 'data',
      ),
      122 => 
      array (
        'show' => 1,
        'name' => lang('menu/website_add'),
        'controller' => 'website',
        'action' => 'info',
      ),
      123 => 
      array (
        'show' => 1,
        'name' => lang('menu/website_data_lock'),
        'controller' => 'website',
        'action' => 'data',
        'param' => 'lock=1',
      ),
      124 => 
      array (
        'show' => 1,
        'name' => lang('menu/website_data_audit'),
        'controller' => 'website',
        'action' => 'data',
        'param' => 'status=0',
      ),
      1291 => 
      array (
        'show' => 1,
        'name' => lang('menu/website_repeat'),
        'controller' => 'website',
        'action' => 'data',
        'param' => 'repeat=1',
      ),
      129 => 
      array (
        'show' => 1,
        'name' => lang('menu/website_batch'),
        'controller' => 'website',
        'action' => 'batch',
      ),
      12101 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/website_info'),
        'controller' => 'website',
        'action' => 'info',
      ),
      12102 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/website_del'),
        'controller' => 'website',
        'action' => 'del',
      ),
      12103 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/website_field'),
        'controller' => 'website',
        'action' => 'field',
      ),
    ),
  ),
  6 => 
  array (
    'name' => lang('menu/live'),
    'icon' => 'xe62b',
    'sub' => 
    array (
      141 => 
      array (
        'show' => 1,
        'name' => lang('menu/live_list'),
        'controller' => 'live',
        'action' => 'index',
      ),
      142 => 
      array (
        'show' => 1,
        'name' => lang('menu/live_category'),
        'controller' => 'live',
        'action' => 'category',
      ),
      1401 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/live_info'),
        'controller' => 'live',
        'action' => 'info',
      ),
      1402 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/live_del'),
        'controller' => 'live',
        'action' => 'del',
      ),
      1403 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/live_field'),
        'controller' => 'live',
        'action' => 'field',
      ),
      1404 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/live_category_info'),
        'controller' => 'live',
        'action' => 'category_info',
      ),
      1405 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/live_category_del'),
        'controller' => 'live',
        'action' => 'category_del',
      ),
    ),
  ),
  7 => 
  array (
    'name' => lang('menu/base'),
    'icon' => 'xe64b',
    'sub' => 
    array (
      31 => 
      array (
        'show' => 1,
        'name' => lang('menu/type'),
        'controller' => 'type',
        'action' => 'index',
      ),
      3101 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/type_info'),
        'controller' => 'type',
        'action' => 'info',
      ),
      3102 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/type_batch'),
        'controller' => 'type',
        'action' => 'batch',
      ),
      3103 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/type_del'),
        'controller' => 'type',
        'action' => 'del',
      ),
      3104 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/type_field'),
        'controller' => 'type',
        'action' => 'field',
      ),
      3105 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/type_extend'),
        'controller' => 'type',
        'action' => 'extend',
      ),
      32 => 
      array (
        'show' => 1,
        'name' => lang('menu/topic'),
        'controller' => 'topic',
        'action' => 'data',
      ),
      3201 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/topic_info'),
        'controller' => 'topic',
        'action' => 'info',
      ),
      3202 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/topic_batch'),
        'controller' => 'topic',
        'action' => 'batch',
      ),
      3203 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/topic_del'),
        'controller' => 'topic',
        'action' => 'del',
      ),
      3204 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/topic_field'),
        'controller' => 'topic',
        'action' => 'field',
      ),
      33 => 
      array (
        'show' => 1,
        'name' => lang('menu/link'),
        'controller' => 'link',
        'action' => 'index',
      ),
      3301 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/link_info'),
        'controller' => 'link',
        'action' => 'info',
      ),
      3302 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/link_batch'),
        'controller' => 'link',
        'action' => 'batch',
      ),
      3303 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/link_del'),
        'controller' => 'link',
        'action' => 'del',
      ),
      3304 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/link_field'),
        'controller' => 'link',
        'action' => 'field',
      ),
      495 => 
      array (
        'show' => 1,
        'name' => lang('menu/actor'),
        'controller' => 'actor',
        'action' => 'data',
      ),
      4951 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/actor_info'),
        'controller' => 'actor',
        'action' => 'info',
      ),
      4952 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/actor_del'),
        'controller' => 'actor',
        'action' => 'del',
      ),
      4953 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/actor_field'),
        'controller' => 'actor',
        'action' => 'field',
      ),
      4954 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/actor_info_add'),
        'controller' => 'actor',
        'action' => 'info',
      ),
      496 => 
      array (
        'show' => 1,
        'name' => lang('menu/role'),
        'controller' => 'role',
        'action' => 'data',
      ),
      4961 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/role_info'),
        'controller' => 'role',
        'action' => 'info',
      ),
      4962 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/role_del'),
        'controller' => 'role',
        'action' => 'del',
      ),
      4963 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/role_field'),
        'controller' => 'role',
        'action' => 'field',
      ),
      4964 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/role_info_add'),
        'controller' => 'role',
        'action' => 'info',
      ),
      38 => 
      array (
        'show' => 1,
        'name' => lang('menu/images'),
        'controller' => 'annex',
        'action' => 'data',
      ),
      3601 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/annex_del'),
        'controller' => 'annex',
        'action' => 'del',
      ),
      3602 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/images_opt'),
        'controller' => 'images',
        'action' => 'opt',
      ),
      3603 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/images_sync'),
        'controller' => 'images',
        'action' => 'sync',
      ),
      3604 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/annex_file'),
        'controller' => 'annex',
        'action' => 'file',
      ),
      3605 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/annex_check'),
        'controller' => 'annex',
        'action' => 'check',
      ),
      3606 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/annex_init'),
        'controller' => 'annex',
        'action' => 'init',
      ),
      673 => 
      array (
        'show' => 1,
        'name' => lang('menu/aiannotation'),
        'controller' => 'ai_annotation',
        'action' => 'index',
      ),
      6731 => 
      array (
        'show' => 0,
        'name' => lang('admin/aiannotation/generate'),
        'controller' => 'ai_annotation',
        'action' => 'generate',
      ),
      6732 => 
      array (
        'show' => 0,
        'name' => lang('admin/aiannotation/adopt'),
        'controller' => 'ai_annotation',
        'action' => 'adopt',
      ),
      6733 => 
      array (
        'show' => 0,
        'name' => lang('admin/aiannotation/reject'),
        'controller' => 'ai_annotation',
        'action' => 'reject',
      ),
      674 => 
      array (
        'show' => 1,
        'name' => lang('menu/content_quality'),
        'controller' => 'content_quality',
        'action' => 'index',
      ),
      6741 => 
      array (
        'show' => 0,
        'name' => lang('admin/content_quality/mark_recommend'),
        'controller' => 'content_quality',
        'action' => 'markRecommend',
      ),
    ),
  ),
  8 => 
  array (
    'name' => lang('menu/interact'),
    'icon' => 'xe62c',
    'sub' => 
    array (
      34 => 
      array (
        'show' => 1,
        'name' => lang('menu/gbook'),
        'controller' => 'gbook',
        'action' => 'data',
      ),
      3401 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/gbook_info'),
        'controller' => 'gbook',
        'action' => 'info',
      ),
      3402 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/gbook_del'),
        'controller' => 'gbook',
        'action' => 'del',
      ),
      3404 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/gbook_field'),
        'controller' => 'gbook',
        'action' => 'field',
      ),
      35 => 
      array (
        'show' => 1,
        'name' => lang('menu/comment'),
        'controller' => 'comment',
        'action' => 'data',
      ),
      3501 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/comment_info'),
        'controller' => 'comment',
        'action' => 'info',
      ),
      3502 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/comment_del'),
        'controller' => 'comment',
        'action' => 'del',
      ),
      3504 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/comment_field'),
        'controller' => 'comment',
        'action' => 'field',
      ),
      37 => 
      array (
        'show' => 1,
        'name' => lang('menu/danmaku'),
        'controller' => 'danmaku',
        'action' => 'data',
      ),
      3710 => 
      array (
        'show' => 0,
        'name' => lang('del'),
        'controller' => 'danmaku',
        'action' => 'del',
      ),
      3711 => 
      array (
        'show' => 0,
        'name' => lang('status'),
        'controller' => 'danmaku',
        'action' => 'field',
      ),
      36 => 
      array (
        'show' => 1,
        'name' => lang('menu/chatroom'),
        'controller' => 'chatroom',
        'action' => 'data',
      ),
      3610 => 
      array (
        'show' => 0,
        'name' => lang('del'),
        'controller' => 'chatroom',
        'action' => 'del',
      ),
      3611 => 
      array (
        'show' => 0,
        'name' => lang('status'),
        'controller' => 'chatroom',
        'action' => 'field',
      ),
      801 => 
      array (
        'show' => 1,
        'name' => lang('menu/dynamics'),
        'controller' => 'dynamics',
        'action' => 'data',
      ),
      8011 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/dynamics_del'),
        'controller' => 'dynamics',
        'action' => 'del',
      ),
      802 => 
      array (
        'show' => 1,
        'name' => lang('menu/follow'),
        'controller' => 'follow',
        'action' => 'data',
      ),
      8021 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/follow_del'),
        'controller' => 'follow',
        'action' => 'del',
      ),
      8022 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/follow_field'),
        'controller' => 'follow',
        'action' => 'field',
      ),
      803 => 
      array (
        'show' => 1,
        'name' => lang('menu/userpm'),
        'controller' => 'userpm',
        'action' => 'data',
      ),
      8031 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/userpm_del'),
        'controller' => 'userpm',
        'action' => 'del',
      ),
      8032 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/userpm_field'),
        'controller' => 'userpm',
        'action' => 'field',
      ),
      804 => 
      array (
        'show' => 1,
        'name' => lang('menu/sharelog'),
        'controller' => 'sharelog',
        'action' => 'data',
      ),
      8041 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/sharelog_del'),
        'controller' => 'sharelog',
        'action' => 'del',
      ),
      805 => 
      array (
        'show' => 1,
        'name' => lang('menu/visit'),
        'controller' => 'visit',
        'action' => 'index',
      ),
      8051 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/visit_del'),
        'controller' => 'visit',
        'action' => 'del',
      ),
      69 => 
      array (
        'show' => 1,
        'name' => lang('menu/notify'),
        'controller' => 'notify',
        'action' => 'index',
      ),
      6901 => 
      array (
        'show' => 0,
        'name' => lang('notify/admin_broadcast'),
        'controller' => 'notify',
        'action' => 'info',
      ),
      6902 => 
      array (
        'show' => 0,
        'name' => lang('del'),
        'controller' => 'notify',
        'action' => 'del',
      ),
      6903 => 
      array (
        'show' => 0,
        'name' => lang('notify/admin_broadcast'),
        'controller' => 'notify',
        'action' => 'broadcast',
      ),
      6904 => 
      array (
        'show' => 0,
        'name' => lang('set'),
        'controller' => 'notify',
        'action' => 'field',
      ),
    ),
  ),
  9 => 
  array (
    'name' => lang('menu/member_biz'),
    'icon' => 'xe62c',
    'sub' => 
    array (
      63 => 
      array (
        'show' => 1,
        'name' => lang('menu/user'),
        'controller' => 'user',
        'action' => 'data',
      ),
      6301 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/user_info'),
        'controller' => 'user',
        'action' => 'info',
      ),
      6302 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/user_del'),
        'controller' => 'user',
        'action' => 'del',
      ),
      6303 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/user_field'),
        'controller' => 'user',
        'action' => 'field',
      ),
      62 => 
      array (
        'show' => 1,
        'name' => lang('menu/group'),
        'controller' => 'group',
        'action' => 'index',
      ),
      6201 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/group_info'),
        'controller' => 'group',
        'action' => 'info',
      ),
      6202 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/group_del'),
        'controller' => 'group',
        'action' => 'del',
      ),
      6203 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/group_field'),
        'controller' => 'group',
        'action' => 'field',
      ),
      901 => 
      array (
        'show' => 1,
        'name' => lang('menu/user_invite'),
        'controller' => 'user',
        'action' => 'invite',
      ),
      902 => 
      array (
        'show' => 1,
        'name' => lang('menu/user_reward'),
        'controller' => 'user',
        'action' => 'reward',
      ),
      64 => 
      array (
        'show' => 1,
        'name' => lang('menu/card'),
        'controller' => 'card',
        'action' => 'index',
      ),
      6401 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/card_info'),
        'controller' => 'card',
        'action' => 'info',
      ),
      6402 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/card_del'),
        'controller' => 'card',
        'action' => 'del',
      ),
      641 => 
      array (
        'show' => 1,
        'name' => lang('menu/mall_goods'),
        'controller' => 'mall_goods',
        'action' => 'index',
      ),
      6411 => 
      array (
        'show' => 0,
        'name' => lang('add'),
        'controller' => 'mall_goods',
        'action' => 'info',
      ),
      6412 => 
      array (
        'show' => 0,
        'name' => lang('del'),
        'controller' => 'mall_goods',
        'action' => 'del',
      ),
      6413 => 
      array (
        'show' => 0,
        'name' => lang('status'),
        'controller' => 'mall_goods',
        'action' => 'field',
      ),
      65 => 
      array (
        'show' => 1,
        'name' => lang('menu/order'),
        'controller' => 'order',
        'action' => 'index',
      ),
      6501 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/order_del'),
        'controller' => 'order',
        'action' => 'del',
      ),
      691 => 
      array (
        'show' => 1,
        'name' => lang('menu/coupon'),
        'controller' => 'coupon',
        'action' => 'index',
      ),
      69101 => 
      array (
        'show' => 0,
        'name' => lang('add'),
        'controller' => 'coupon',
        'action' => 'info',
      ),
      69102 => 
      array (
        'show' => 0,
        'name' => lang('del'),
        'controller' => 'coupon',
        'action' => 'del',
      ),
      69103 => 
      array (
        'show' => 0,
        'name' => lang('set'),
        'controller' => 'coupon',
        'action' => 'field',
      ),
      69104 => 
      array (
        'show' => 0,
        'name' => lang('save'),
        'controller' => 'coupon',
        'action' => 'save',
      ),
      692 => 
      array (
        'show' => 1,
        'name' => lang('menu/seckill'),
        'controller' => 'seckill',
        'action' => 'index',
      ),
      69201 => 
      array (
        'show' => 0,
        'name' => lang('add'),
        'controller' => 'seckill',
        'action' => 'info',
      ),
      69202 => 
      array (
        'show' => 0,
        'name' => lang('del'),
        'controller' => 'seckill',
        'action' => 'del',
      ),
      69203 => 
      array (
        'show' => 0,
        'name' => lang('set'),
        'controller' => 'seckill',
        'action' => 'field',
      ),
      69204 => 
      array (
        'show' => 0,
        'name' => lang('save'),
        'controller' => 'seckill',
        'action' => 'save',
      ),
      68 => 
      array (
        'show' => 1,
        'name' => lang('menu/cash'),
        'controller' => 'cash',
        'action' => 'index',
      ),
      6801 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/cash_del'),
        'controller' => 'cash',
        'action' => 'del',
      ),
      6802 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/cash_audit'),
        'controller' => 'cash',
        'action' => 'audit',
      ),
      67 => 
      array (
        'show' => 1,
        'name' => lang('menu/plog'),
        'controller' => 'plog',
        'action' => 'index',
      ),
      6701 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/plog_del'),
        'controller' => 'plog',
        'action' => 'del',
      ),
      39 => 
      array (
        'show' => 1,
        'name' => lang('menu/task'),
        'controller' => 'task',
        'action' => 'data',
      ),
      3901 => 
      array (
        'show' => 0,
        'name' => lang('add'),
        'controller' => 'task',
        'action' => 'info',
      ),
      3902 => 
      array (
        'show' => 0,
        'name' => lang('del'),
        'controller' => 'task',
        'action' => 'del',
      ),
      3903 => 
      array (
        'show' => 0,
        'name' => lang('status'),
        'controller' => 'task',
        'action' => 'field',
      ),
      3904 => 
      array (
        'show' => 1,
        'name' => lang('menu/task_log'),
        'controller' => 'task',
        'action' => 'log',
      ),
      3905 => 
      array (
        'show' => 0,
        'name' => lang('del'),
        'controller' => 'task',
        'action' => 'log_del',
      ),
      3910 => 
      array (
        'show' => 1,
        'name' => lang('menu/sign_milestone'),
        'controller' => 'sign_milestone',
        'action' => 'data',
      ),
      3911 => 
      array (
        'show' => 0,
        'name' => lang('add'),
        'controller' => 'sign_milestone',
        'action' => 'info',
      ),
      3912 => 
      array (
        'show' => 0,
        'name' => lang('del'),
        'controller' => 'sign_milestone',
        'action' => 'del',
      ),
      3913 => 
      array (
        'show' => 0,
        'name' => lang('status'),
        'controller' => 'sign_milestone',
        'action' => 'field',
      ),
    ),
  ),
  10 => 
  array (
    'name' => lang('menu/ui_make'),
    'icon' => 'xe72d',
    'sub' => 
    array (
      71 => 
      array (
        'show' => 1,
        'name' => lang('menu/template'),
        'controller' => 'template',
        'action' => 'index',
      ),
      7101 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/template_info'),
        'controller' => 'template',
        'action' => 'info',
      ),
      7102 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/template_del'),
        'controller' => 'template',
        'action' => 'del',
      ),
      74 => 
      array (
        'show' => 1,
        'name' => lang('menu/theme/config'),
        'controller' => 'tpl_config',
        'action' => 'theme',
      ),
      75 => 
      array (
        'show' => 1,
        'name' => lang('menu/theme_design'),
        'controller' => 'theme_design',
        'action' => 'design',
      ),
      7501 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/theme_design_index'),
        'controller' => 'theme_design',
        'action' => 'index',
      ),
      7502 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/theme_design_load'),
        'controller' => 'theme_design',
        'action' => 'load',
      ),
      7503 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/theme_design_save_step'),
        'controller' => 'theme_design',
        'action' => 'save_step',
      ),
      7504 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/theme_design_preview'),
        'controller' => 'theme_design',
        'action' => 'preview',
      ),
      7505 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/theme_design_publish'),
        'controller' => 'theme_design',
        'action' => 'publish',
      ),
      7506 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/theme_design_history'),
        'controller' => 'theme_design',
        'action' => 'history',
      ),
      7507 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/theme_design_rollback'),
        'controller' => 'theme_design',
        'action' => 'rollback',
      ),
      7508 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/theme_design_upload'),
        'controller' => 'theme_design',
        'action' => 'upload',
      ),
      7509 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/theme_design_generate'),
        'controller' => 'theme_design',
        'action' => 'generate',
      ),
      7510 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/theme_design_detect'),
        'controller' => 'theme_design',
        'action' => 'detect',
      ),
      7511 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/theme_design_generate_apply'),
        'controller' => 'theme_design',
        'action' => 'generate_apply',
      ),
      7512 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/theme_design_gen_image'),
        'controller' => 'theme_design',
        'action' => 'gen_image',
      ),
      72 => 
      array (
        'show' => 1,
        'name' => lang('menu/ads'),
        'controller' => 'template',
        'action' => 'ads',
      ),
      73 => 
      array (
        'show' => 1,
        'name' => lang('menu/wizard'),
        'controller' => 'template',
        'action' => 'wizard',
      ),
      76 => 
      array (
        'show' => 1,
        'name' => lang('menu/template_market'),
        'controller' => 'template_market',
        'action' => 'index',
      ),
      7601 => 
      array (
        'show' => 0,
        'name' => lang('admin/template_market/refresh_menu'),
        'controller' => 'template_market',
        'action' => 'refresh',
      ),
      7602 => 
      array (
        'show' => 0,
        'name' => lang('admin/template_market/install_menu'),
        'controller' => 'template_market',
        'action' => 'install',
      ),
      7603 => 
      array (
        'show' => 0,
        'name' => lang('admin/template_market/activate_menu'),
        'controller' => 'template_market',
        'action' => 'activate',
      ),
      81 => 
      array (
        'show' => 1,
        'name' => lang('menu/make_opt'),
        'controller' => 'make',
        'action' => 'opt',
      ),
      82 => 
      array (
        'show' => 1,
        'name' => lang('menu/make_index'),
        'controller' => 'make',
        'action' => 'index',
      ),
      821 => 
      array (
        'show' => 1,
        'name' => lang('menu/make_index_wap'),
        'controller' => 'make',
        'action' => 'index?ac2=wap',
      ),
      83 => 
      array (
        'show' => 1,
        'name' => lang('menu/make_map'),
        'controller' => 'make',
        'action' => 'map',
      ),
      8101 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/make_make'),
        'controller' => 'make',
        'action' => 'make',
      ),
      8102 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/make_rss'),
        'controller' => 'make',
        'action' => 'rss',
      ),
      8103 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/make_type'),
        'controller' => 'make',
        'action' => 'type',
      ),
      8104 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/make_topic_index'),
        'controller' => 'make',
        'action' => 'topic_index',
      ),
      8105 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/make_topic_info'),
        'controller' => 'make',
        'action' => 'topic_info',
      ),
      8106 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/make_info'),
        'controller' => 'make',
        'action' => 'info',
      ),
      8107 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/make_label'),
        'controller' => 'make',
        'action' => 'label',
      ),
    ),
  ),
  11 => 
  array (
    'name' => lang('menu/cjs'),
    'icon' => 'xe727',
    'sub' => 
    array (
      93 => 
      array (
        'show' => 1,
        'name' => lang('menu/collect'),
        'controller' => 'collect',
        'action' => 'index',
      ),
      9301 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/collect_info'),
        'controller' => 'collect',
        'action' => 'info',
      ),
      9302 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/collect_del'),
        'controller' => 'collect',
        'action' => 'del',
      ),
      94 => 
      array (
        'show' => 1,
        'name' => lang('menu/cj'),
        'controller' => 'cj',
        'action' => 'index',
      ),
      9401 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/cj_info'),
        'controller' => 'cj',
        'action' => 'info',
      ),
      9402 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/cj_del'),
        'controller' => 'cj',
        'action' => 'del',
      ),
      9403 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/cj_program'),
        'controller' => 'cj',
        'action' => 'program',
      ),
      9404 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/cj_col_url'),
        'controller' => 'cj',
        'action' => 'col_url',
      ),
      9405 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/cj_col_content'),
        'controller' => 'cj',
        'action' => 'col_content',
      ),
      9406 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/cj_publish'),
        'controller' => 'cj',
        'action' => 'publish',
      ),
      9407 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/cj_export'),
        'controller' => 'cj',
        'action' => 'export',
      ),
      9408 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/cj_import'),
        'controller' => 'cj',
        'action' => 'import',
      ),
      95 => 
      array (
        'show' => 1,
        'name' => lang('admin/resourcehub/title'),
        'controller' => 'resource_hub',
        'action' => 'index',
      ),
      9501 => 
      array (
        'show' => 0,
        'name' => lang('admin/resourcehub/multi_collect_menu'),
        'controller' => 'resource_hub',
        'action' => 'multi_collect',
      ),
      9502 => 
      array (
        'show' => 0,
        'name' => lang('admin/resourcehub/poster_menu'),
        'controller' => 'resource_hub',
        'action' => 'poster',
      ),
      91 => 
      array (
        'show' => 0,
        'name' => lang('menu/union'),
        'controller' => 'collect',
        'action' => 'union',
      ),
      9101 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/collect_api'),
        'controller' => 'collect',
        'action' => 'api',
      ),
      9102 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/collect_load'),
        'controller' => 'collect',
        'action' => 'load',
      ),
      9103 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/collect_bind'),
        'controller' => 'collect',
        'action' => 'bind',
      ),
      9104 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/collect_vod'),
        'controller' => 'collect',
        'action' => 'vod',
      ),
      9105 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/collect_art'),
        'controller' => 'collect',
        'action' => 'art',
      ),
      92 => 
      array (
        'show' => 0,
        'name' => lang('menu/collect_timming'),
        'controller' => 'collect',
        'action' => 'timing',
      ),
    ),
  ),
  12 => 
  array (
    'name' => lang('menu/system'),
    'icon' => 'xe62e',
    'sub' => 
    array (
      20 => 
      array (
        'show' => 1,
        'name' => lang('menu/systeminfo'),
        'controller' => 'system',
        'action' => 'index',
      ),
      21 => 
      array (
        'show' => 1,
        'name' => lang('menu/config'),
        'controller' => 'system',
        'action' => 'config',
      ),
      210 => 
      array (
        'show' => 0,
        'name' => lang('menu/configseo'),
        'controller' => 'system',
        'action' => 'configseo',
      ),
      211 => 
      array (
        'show' => 1,
        'name' => lang('menu/configuser'),
        'controller' => 'system',
        'action' => 'configuser',
      ),
      2111 => 
      array (
        'show' => 1,
        'name' => lang('menu/configuseraccess'),
        'controller' => 'system',
        'action' => 'configuseraccess',
      ),
      212 => 
      array (
        'show' => 0,
        'name' => lang('menu/configcomment'),
        'controller' => 'system',
        'action' => 'configcomment',
      ),
      213 => 
      array (
        'show' => 1,
        'name' => lang('menu/configupload'),
        'controller' => 'system',
        'action' => 'configupload',
      ),
      214 => 
      array (
        'show' => 0,
        'name' => lang('menu/configaiseo'),
        'controller' => 'system',
        'action' => 'configaiseo',
      ),
      2141 => 
      array (
        'show' => 0,
        'name' => lang('menu/configaicover'),
        'controller' => 'system',
        'action' => 'configaicover',
      ),
      215 => 
      array (
        'show' => 1,
        'name' => lang('menu/configaisearch'),
        'controller' => 'system',
        'action' => 'configaisearch',
      ),
      2151 => 
      array (
        'show' => 0,
        'name' => lang('menu/meilisearch_save'),
        'controller' => 'meilisearch',
        'action' => 'save',
      ),
      2152 => 
      array (
        'show' => 0,
        'name' => lang('menu/meilisearch_status'),
        'controller' => 'meilisearch',
        'action' => 'status',
      ),
      2153 => 
      array (
        'show' => 0,
        'name' => lang('menu/meilisearch_selfcheck'),
        'controller' => 'meilisearch',
        'action' => 'selfcheck',
      ),
      2154 => 
      array (
        'show' => 0,
        'name' => lang('menu/meilisearch_sync'),
        'controller' => 'meilisearch',
        'action' => 'sync',
      ),
      2155 => 
      array (
        'show' => 0,
        'name' => lang('menu/meilisearch_setup'),
        'controller' => 'meilisearch',
        'action' => 'setup',
      ),
      216 => 
      array (
        'show' => 1,
        'name' => lang('menu/meilisearch'),
        'controller' => 'meilisearch',
        'action' => 'index',
      ),
      217 => 
      array (
        'show' => 1,
        'name' => lang('menu/configassistant'),
        'controller' => 'system',
        'action' => 'configassistant',
      ),
      218 => 
      array (
        'show' => 1,
        'name' => lang('menu/configthemeai'),
        'controller' => 'system',
        'action' => 'configthemeai',
      ),
      219 => 
      array (
        'show' => 1,
        'name' => lang('admin/system/configaicontent'),
        'controller' => 'system',
        'action' => 'configaicontent',
      ),
      220 => 
      array (
        'show' => 1,
        'name' => lang('menu/configtranslate'),
        'controller' => 'translate',
        'action' => 'index',
      ),
      2201 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/translate_save'),
        'controller' => 'translate',
        'action' => 'save',
      ),
      2202 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/translate_test'),
        'controller' => 'translate',
        'action' => 'test',
      ),
      22 => 
      array (
        'show' => 0,
        'name' => lang('menu/configurl'),
        'controller' => 'system',
        'action' => 'configurl',
      ),
      23 => 
      array (
        'show' => 0,
        'name' => lang('menu/configplay'),
        'controller' => 'system',
        'action' => 'configplay',
      ),
      24 => 
      array (
        'show' => 1,
        'name' => lang('menu/configcollect'),
        'controller' => 'system',
        'action' => 'configcollect',
      ),
      25 => 
      array (
        'show' => 0,
        'name' => lang('menu/configinterface'),
        'controller' => 'system',
        'action' => 'configinterface',
      ),
      26 => 
      array (
        'show' => 0,
        'name' => lang('menu/configapi'),
        'controller' => 'system',
        'action' => 'configapi',
      ),
      27 => 
      array (
        'show' => 1,
        'name' => lang('menu/configconnect'),
        'controller' => 'system',
        'action' => 'configconnect',
      ),
      28 => 
      array (
        'show' => 0,
        'name' => lang('menu/configpay'),
        'controller' => 'system',
        'action' => 'configpay',
      ),
      29 => 
      array (
        'show' => 0,
        'name' => lang('menu/configweixin'),
        'controller' => 'system',
        'action' => 'configweixin',
      ),
      291 => 
      array (
        'show' => 1,
        'name' => lang('menu/configemail'),
        'controller' => 'system',
        'action' => 'configemail',
      ),
      292 => 
      array (
        'show' => 1,
        'name' => lang('menu/configsms'),
        'controller' => 'system',
        'action' => 'configsms',
      ),
      293 => 
      array (
        'show' => 1,
        'name' => lang('menu/configpush'),
        'controller' => 'system',
        'action' => 'configpush',
      ),
      112 => 
      array (
        'show' => 1,
        'name' => lang('menu/urlsend'),
        'controller' => 'urlsend',
        'action' => 'index',
      ),
      11200 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/urlsend_push'),
        'controller' => 'urlsend',
        'action' => 'push',
      ),
      11201 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/urlsend_baidu_push'),
        'controller' => 'urlsend',
        'action' => 'baidu_push',
      ),
      11202 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/urlsend_baidu_bear'),
        'controller' => 'urlsend',
        'action' => 'baidu_bear',
      ),
      2910 => 
      array (
        'show' => 1,
        'name' => lang('menu/timming'),
        'controller' => 'timming',
        'action' => 'index',
      ),
      2911 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/timming_info'),
        'controller' => 'timming',
        'action' => 'info',
      ),
      2912 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/timming_del'),
        'controller' => 'timming',
        'action' => 'del',
      ),
      2913 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/timming_field'),
        'controller' => 'timming',
        'action' => 'field',
      ),
      2920 => 
      array (
        'show' => 1,
        'name' => lang('menu/domain'),
        'controller' => 'domain',
        'action' => 'index',
      ),
      2922 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/domain_del'),
        'controller' => 'domain',
        'action' => 'del',
      ),
      2923 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/domain_export'),
        'controller' => 'domain',
        'action' => 'export',
      ),
      2924 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/domain_import'),
        'controller' => 'domain',
        'action' => 'import',
      ),
      295 => 
      array (
        'show' => 1,
        'name' => lang('admin/apidoc/title'),
        'controller' => 'api_doc',
        'action' => 'index',
      ),
      2951 => 
      array (
        'show' => 0,
        'name' => lang('admin/apidoc/openapi_menu'),
        'controller' => 'api_doc',
        'action' => 'openapi',
      ),
      111 => 
      array (
        'show' => 1,
        'name' => lang('menu/addon'),
        'controller' => 'addon',
        'action' => 'index',
      ),
      11100 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/addon_downloaded'),
        'controller' => 'addon',
        'action' => 'downloaded',
      ),
      11101 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/addon_install'),
        'controller' => 'addon',
        'action' => 'install',
      ),
      11102 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/addon_uninstall'),
        'controller' => 'addon',
        'action' => 'uninstall',
      ),
      11103 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/addon_config'),
        'controller' => 'addon',
        'action' => 'config',
      ),
      11104 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/addon_state'),
        'controller' => 'addon',
        'action' => 'state',
      ),
      11105 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/addon_local'),
        'controller' => 'addon',
        'action' => 'local',
      ),
      11106 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/addon_upgrade'),
        'controller' => 'addon',
        'action' => 'upgrade',
      ),
      11107 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/addon_add'),
        'controller' => 'addon',
        'action' => 'add',
      ),
      11108 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/addon_cloudrefresh'),
        'controller' => 'addon',
        'action' => 'cloudrefresh',
      ),
      11109 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/addon_cloudinstall'),
        'controller' => 'addon',
        'action' => 'cloudinstall',
      ),
      11110 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/addon_cloudcatalog'),
        'controller' => 'addon',
        'action' => 'cloudcatalog',
      ),
      61 => 
      array (
        'show' => 1,
        'name' => lang('menu/admin'),
        'controller' => 'admin',
        'action' => 'index',
      ),
      6101 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/admin_info'),
        'controller' => 'admin',
        'action' => 'info',
      ),
      6102 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/admin_del'),
        'controller' => 'admin',
        'action' => 'del',
      ),
      6103 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/admin_field'),
        'controller' => 'admin',
        'action' => 'field',
      ),
    ),
  ),
  13 => 
  array (
    'name' => lang('menu/ops'),
    'icon' => 'xe621',
    'sub' => 
    array (
      101 => 
      array (
        'show' => 1,
        'name' => lang('menu/database'),
        'controller' => 'database',
        'action' => 'index',
      ),
      10001 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/database_export'),
        'controller' => 'database',
        'action' => 'export',
      ),
      10002 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/database_import'),
        'controller' => 'database',
        'action' => 'import',
      ),
      10003 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/database_optimize'),
        'controller' => 'database',
        'action' => 'optimize',
      ),
      10004 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/database_repair'),
        'controller' => 'database',
        'action' => 'repair',
      ),
      10005 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/database_del'),
        'controller' => 'database',
        'action' => 'del',
      ),
      10006 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/database_columns'),
        'controller' => 'database',
        'action' => 'columns',
      ),
      10007 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/database_uploadstorage'),
        'controller' => 'database',
        'action' => 'uploadstorage',
      ),
      102 => 
      array (
        'show' => 1,
        'name' => lang('menu/database_sql'),
        'controller' => 'database',
        'action' => 'sql',
      ),
      103 => 
      array (
        'show' => 1,
        'name' => lang('menu/database_rep'),
        'controller' => 'database',
        'action' => 'rep',
      ),
      97 => 
      array (
        'show' => 1,
        'name' => lang('admin/datareplace/title'),
        'controller' => 'data_replace',
        'action' => 'index',
      ),
      9701 => 
      array (
        'show' => 0,
        'name' => lang('admin/datareplace/do_replace_menu'),
        'controller' => 'data_replace',
        'action' => 'doReplace',
      ),
      113 => 
      array (
        'show' => 1,
        'name' => lang('menu/safety_file'),
        'controller' => 'safety',
        'action' => 'file',
      ),
      11301 => 
      array (
        'show' => 1,
        'name' => lang('menu/safety_checkup'),
        'controller' => 'safety',
        'action' => 'checkup',
      ),
      114 => 
      array (
        'show' => 1,
        'name' => lang('menu/safety_data'),
        'controller' => 'safety',
        'action' => 'data',
      ),
      11401 => 
      array (
        'show' => 0,
        'name' => lang('admin/safety/fix_menu'),
        'controller' => 'safety',
        'action' => 'fix',
      ),
      151 => 
      array (
        'show' => 1,
        'name' => lang('menu/monitor_server'),
        'controller' => 'monitor',
        'action' => 'index',
      ),
      152 => 
      array (
        'show' => 1,
        'name' => lang('menu/monitor_realtime'),
        'controller' => 'monitor',
        'action' => 'realtime',
      ),
      153 => 
      array (
        'show' => 1,
        'name' => lang('menu/monitor_setting'),
        'controller' => 'monitor',
        'action' => 'setting',
      ),
      1501 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/monitor_series'),
        'controller' => 'monitor',
        'action' => 'series',
      ),
      1502 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/monitor_live'),
        'controller' => 'monitor',
        'action' => 'live',
      ),
      1503 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/monitor_setting_save'),
        'controller' => 'monitor',
        'action' => 'setting_save',
      ),
      1504 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/monitor_cron_token_reset'),
        'controller' => 'monitor',
        'action' => 'cron_token_reset',
      ),
      154 => 
      array (
        'show' => 1,
        'name' => lang('menu/monitor_rule'),
        'controller' => 'monitor',
        'action' => 'rule',
      ),
      1505 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/monitor_rule_info'),
        'controller' => 'monitor',
        'action' => 'rule_info',
      ),
      1506 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/monitor_rule_save'),
        'controller' => 'monitor',
        'action' => 'rule_save',
      ),
      1507 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/monitor_rule_del'),
        'controller' => 'monitor',
        'action' => 'rule_del',
      ),
      1508 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/monitor_rule_field'),
        'controller' => 'monitor',
        'action' => 'rule_field',
      ),
      1509 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/monitor_rule_enable_recommended'),
        'controller' => 'monitor',
        'action' => 'rule_enable_recommended',
      ),
      1510 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/monitor_rule_test'),
        'controller' => 'monitor',
        'action' => 'rule_test',
      ),
      155 => 
      array (
        'show' => 1,
        'name' => lang('menu/monitor_event'),
        'controller' => 'monitor',
        'action' => 'event',
      ),
      1511 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/monitor_event_ack'),
        'controller' => 'monitor',
        'action' => 'event_ack',
      ),
      156 => 
      array (
        'show' => 1,
        'name' => lang('menu/monitor_access'),
        'controller' => 'monitor',
        'action' => 'access',
      ),
      1512 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/monitor_access_ban'),
        'controller' => 'monitor',
        'action' => 'access_ban',
      ),
      1513 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/monitor_access_unban'),
        'controller' => 'monitor',
        'action' => 'access_unban',
      ),
      66 => 
      array (
        'show' => 1,
        'name' => lang('menu/ulog'),
        'controller' => 'ulog',
        'action' => 'index',
      ),
      6601 => 
      array (
        'show' => 0,
        'name' => lang('admin/perm/ulog_del'),
        'controller' => 'ulog',
        'action' => 'del',
      ),
      661 => 
      array (
        'show' => 1,
        'name' => lang('menu/user_access_log'),
        'controller' => 'user_access_log',
        'action' => 'index',
      ),
      6611 => 
      array (
        'show' => 0,
        'name' => lang('del'),
        'controller' => 'user_access_log',
        'action' => 'del',
      ),
      6612 => 
      array (
        'show' => 0,
        'name' => lang('detail'),
        'controller' => 'user_access_log',
        'action' => 'info',
      ),
      6613 => 
      array (
        'show' => 0,
        'name' => lang('admin/monitor/ban'),
        'controller' => 'user_access_log',
        'action' => 'ban',
      ),
      6614 => 
      array (
        'show' => 0,
        'name' => lang('admin/monitor/unban'),
        'controller' => 'user_access_log',
        'action' => 'unban',
      ),
      672 => 
      array (
        'show' => 1,
        'name' => lang('menu/adminaudit'),
        'controller' => 'adminaudit',
        'action' => 'index',
      ),
      67201 => 
      array (
        'show' => 0,
        'name' => lang('admin/adminaudit/menu_info'),
        'controller' => 'adminaudit',
        'action' => 'info',
      ),
    ),
  ),
);
