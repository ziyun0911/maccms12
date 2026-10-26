<?php

namespace addons\aicontent\controller;

use app\common\addon\Controller;
use addons\aicontent\model\AiTask;
use addons\aicontent\service\ModelFactory;
use addons\aicontent\Aicontent;

class Admin extends Controller
{
    protected $noNeedLogin  = [];
    protected $noNeedRight  = [];

    public function _initialize()
    {
        parent::_initialize();

        if (session('admin_auth') !== '1' || empty(session('admin_info'))) {
            echo json_encode(['code' => 0, 'message' => lang('Session expired. Please log in to the admin panel and try again.')]);
            exit;
        }

        if ($this->request->isPost()) {
            $token    = input('_csrf_token', '');
            $expected = Aicontent::generateCsrfToken();
            if (!hash_equals($expected, $token)) {
                echo json_encode(['code' => 0, 'message' => lang('Invalid request token.')]);
                exit;
            }
        }
    }

    public function index()
    {
        $page    = (int) input('page', 1);
        $history = AiTask::getHistory($page, 20);

        $stats = [
            'total'   => AiTask::count(),
            'done'    => AiTask::where('status', AiTask::STATUS_DONE)->count(),
            'pending' => AiTask::where('status', AiTask::STATUS_PENDING)->count(),
            'error'   => AiTask::where('status', AiTask::STATUS_ERROR)->count(),
        ];

        return $this->fetch('admin/index', [
            'tasks'     => $history['list'],
            'total'     => $history['total'],
            'page'      => $page,
            'stats'     => $stats,
            'providers' => ModelFactory::getProviders(),
            'jsLang'    => Aicontent::jsLangJson(),
        ]);
    }

    public function generate()
    {
        $config    = addon_config('aicontent');
        $providers = ModelFactory::getProviders();

        $modelsMap = [];
        foreach (array_keys($providers) as $slug) {
            $modelsMap[$slug] = ModelFactory::getModelsForProvider($slug);
        }

        return $this->fetch('admin/generate', [
            'config'    => $config,
            'providers' => $providers,
            'modelsMap' => json_encode($modelsMap),
            'jsLang'    => Aicontent::jsLangJson(),
        ]);
    }

    public function view()
    {
        $id   = (int) input('id');
        $task = AiTask::find($id);

        if (!$task) {
            return $this->error(lang('Task not found'));
        }

        $result = null;
        if ($task->result) {
            $result = json_decode($task->result, true);
        }

        return $this->fetch('admin/view', [
            'task'   => $task->toArray(),
            'result' => $result,
        ]);
    }

    public function delete()
    {
        $id   = (int) input('id');
        $task = AiTask::find($id);

        if (!$task) {
            return json(['code' => 0, 'message' => lang('Task not found')]);
        }

        $task->delete();
        return json(['code' => 1, 'message' => lang('Deleted successfully')]);
    }
}
