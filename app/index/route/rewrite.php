<?php
use think\facade\Route;

Route::pattern(array (
  'id' => '[\\s\\S]*?',
  'ids' => '[\\s\\S]*?',
  'wd' => '[^-\\n]*',
  'en' => '[^-\\n]*',
  'state' => '[^-\\n]*',
  'area' => '[^-\\n]*',
  'year' => '[^-\\n]*',
  'lang' => '[^-\\n]*',
  'letter' => '[^-\\n]*',
  'actor' => '[^-\\n]*',
  'director' => '[^-\\n]*',
  'tag' => '[^-\\n]*',
  'class' => '[^-\\n]*',
  'order' => '[^-\\n]*',
  'by' => '[^-\\n]*',
  'file' => '[^-\\n]*',
  'name' => '[^-\\n]*',
  'url' => '[^-\\n]*',
  'type' => '[^-\\n]*',
  'sex' => '[^-\\n]*',
  'version' => '[^-\\n]*',
  'blood' => '[^-\\n]*',
  'starsign' => '[^-\\n]*',
  'page' => '\\d+',
  'ajax' => '\\d+',
  'tid' => '\\d+',
  'mid' => '\\d+',
  'rid' => '\\d+',
  'pid' => '\\d+',
  'sid' => '\\d+',
  'nid' => '\\d+',
  'uid' => '\\d+',
  'level' => '\\d+',
  'score' => '\\d+',
  'limit' => '\\d+',
));

Route::rule('sitehome', 'index/home');
Route::rule('publish-<id>', 'index/publish_group');
Route::rule('map', 'map/index');
Route::rule('rss', 'rss/index');
Route::rule('rss/index', 'rss/index');
Route::rule('rss/baidu', 'rss/baidu');
Route::rule('rss/google', 'rss/google');
Route::rule('rss/sogou', 'rss/sogou');
Route::rule('rss/so', 'rss/so');
Route::rule('rss/bing', 'rss/bing');
Route::rule('rss/sm', 'rss/sm');
Route::rule('index-<page?>', 'index/index');
Route::rule('gbook-<page?>', 'gbook/index');
Route::rule('gbook$', 'gbook/index');
Route::rule('topic-<page?>', 'topic/index');
Route::rule('topic$', 'topic/index');
Route::rule('topicdetail-<id>', 'topic/detail');
Route::rule('actor-<page?>', 'actor/index');
Route::rule('actor$', 'actor/index');
Route::rule('actor/show', 'actor/show');
Route::rule('actor/show/<page?>', 'actor/show');
Route::rule('actorshow/<area?>-<blood?>-<by?>-<letter?>-<level?>-<order?>-<page?>-<sex?>-<starsign?>', 'actor/show');
Route::rule('role-<page?>', 'role/index');
Route::rule('role$', 'role/index');
Route::rule('roledetail-<id>', 'role/detail');
Route::rule('roleshow/<by?>-<letter?>-<level?>-<order?>-<page?>-<rid?>', 'role/show');
Route::rule('vod/type/<id>-<page?>', 'vod/type');
Route::rule('vod/type/<id>', 'vod/type');
Route::rule('vod/detail/id/<id>', 'vod/detail');
Route::rule('vod/play/id/<id>/sid/<sid>/nid/<nid>', 'vod/play');
Route::rule('vod/down/id/<id>/sid/<sid>/nid/<nid>', 'vod/down');
Route::rule('vod/show/id/<id>', 'vod/show');
Route::rule('vod/search', 'vod/search');
Route::rule('vod/plot/id/<id>', 'vod/plot');
Route::rule('vodtype/<id>-<page?>', 'vod/type');
Route::rule('vodtype/<id>', 'vod/type');
Route::rule('voddetail/<id>', 'vod/detail');
Route::rule('vodrss-<id>', 'vod/rss');
Route::rule('vodplay/<id>-<sid>-<nid>', 'vod/play');
Route::rule('voddown/<id>-<sid>-<nid>', 'vod/down');
Route::rule('vodshow/<id>-<area?>-<by?>-<class?>-<lang?>-<letter?>-<level?>-<order?>-<page?>-<state?>-<tag?>-<year?>', 'vod/show');
Route::rule('vodsearch/<wd?>-<actor?>-<area?>-<by?>-<class?>-<director?>-<lang?>-<letter?>-<level?>-<order?>-<page?>-<state?>-<tag?>-<year?>', 'vod/search');
Route::rule('vodplot/<id>-<page?>', 'vod/plot');
Route::rule('vodplot/<id>', 'vod/plot');
Route::rule('art/type/<id>-<page?>', 'art/type');
Route::rule('art/type/<id>', 'art/type');
Route::rule('art/detail/id/<id>', 'art/detail');
Route::rule('art/read/id/<id>-<page?>', 'art/read');
Route::rule('art/show/id/<id>', 'art/show');
Route::rule('art/search', 'art/search');
Route::rule('arttype/<id>-<page?>', 'art/type');
Route::rule('arttype/<id>', 'art/type');
Route::rule('artshow-<id>', 'art/show');
Route::rule('artdetail-<id>-<page?>', 'art/detail');
Route::rule('artdetail-<id>', 'art/detail');
Route::rule('artrss-<id>-<page>', 'art/rss');
Route::rule('artshow/<id>-<by?>-<class?>-<level?>-<letter?>-<order?>-<page?>-<tag?>', 'art/show');
Route::rule('artsearch/<wd?>-<by?>-<class?>-<level?>-<letter?>-<order?>-<page?>-<tag?>', 'art/search');
Route::rule('artread/<id>-<page?>', 'art/read');
Route::rule('manga-<page?>', 'manga/index');
Route::rule('manga$', 'manga/index');
Route::rule('manga/type/<id>-<page?>', 'manga/type');
Route::rule('manga/type/<id>', 'manga/type');
Route::rule('manga/detail/id/<id>', 'manga/detail');
Route::rule('manga/play/id/<id>/sid/<sid>/nid/<nid>', 'manga/play');
Route::rule('manga/down/id/<id>/sid/<sid>/nid/<nid>', 'manga/down');
Route::rule('manga/show/id/<id>', 'manga/show');
Route::rule('manga/search', 'manga/search');
Route::rule('mangatype/<id>-<page?>', 'manga/type');
Route::rule('mangatype/<id>', 'manga/type');
Route::rule('mangadetail-<id>', 'manga/detail');
Route::rule('mangaplay/<id>-<sid>-<nid>', 'manga/play');
Route::rule('mangadown/<id>-<sid>-<nid>', 'manga/down');
Route::rule('mangashow/<id>-<area?>-<by?>-<class?>-<lang?>-<letter?>-<level?>-<order?>-<page?>-<state?>-<tag?>-<year?>', 'manga/show');
Route::rule('mangasearch/<wd?>-<actor?>-<area?>-<by?>-<class?>-<director?>-<lang?>-<letter?>-<level?>-<order?>-<page?>-<state?>-<tag?>-<year?>', 'manga/search');
Route::rule('label-<file>', 'label/index');
Route::rule('plotdetail/<id>-<page?>', 'plot/detail');
Route::rule('plotdetail/<id>', 'plot/detail');
Route::rule('live/show', 'live/show');
Route::rule('live/play/id/<id>', 'live/play');
Route::rule('liveshow', 'live/show');
Route::rule('liveplay/<id>', 'live/play');
