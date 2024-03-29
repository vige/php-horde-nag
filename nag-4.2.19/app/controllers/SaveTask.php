<?php
class Nag_SaveTask_Controller extends Horde_Controller_Base
{
    public function processRequest(Horde_Controller_Request $request, Horde_Controller_Response $response)
    {
        global $nag_shares, $prefs;

        $vars = Horde_Variables::getDefaultVariables();
        $registry = $this->getInjector()->getInstance('Horde_Registry');
        $notification = $this->getInjector()->getInstance('Horde_Notification');

        $form = new Nag_Form_Task($vars, $vars->get('task_id') ? sprintf(_("Edit: %s"), $vars->get('name')) : _("New Task"));
        if (!$form->validate($vars)) {
            // Hideous
            $_REQUEST['actionID'] = 'task_form';
            require NAG_BASE . '/task.php';
            exit;
        }
        $form->getInfo($vars, $info);

        // Check if we are here due to a search_return push.
        if ($vars->search_return) {
            Horde::url('list.php', true)->add(array('actionID' => 'search_return', 'list' => $vars->list, 'tab_name' => $vars->tab_name))->redirect();
        }
        // Check if we are here due to a deletebutton push
        if ($vars->deletebutton) {
            try {
                $share = $nag_shares->getShare($info['old_tasklist']);
            } catch (Horde_Share_Exception $e) {
                $notification->push(sprintf(_("Access denied deleting task: %s"), $e->getMessage()), 'horde.error');
                Horde::url('list.php', true)->redirect();
            }
            $task = Nag::getTask($info['old_tasklist'], $info['task_id']);
            $task->loadChildren();
            if (!$share->hasPermission($registry->getAuth(), Horde_Perms::DELETE)) {
                $notification->push(_("Access denied deleting task"), 'horde.error');
                Horde::url('list.php', true)->redirect();
            } else {
                $storage = $this->getInjector()
                    ->getInstance('Nag_Factory_Driver')
                    ->create($info['old_tasklist']);
                try {
                    $storage->delete($info['task_id']);
                    $notification->push(_("Task successfully deleted"), 'horde.success');
                    Horde::url('list.php', true)->redirect();
                } catch (Nag_Exception $e) {
                    $notification->push(sprintf(_("Error deleting task: %s"), $e->getMessage()), 'horde.error');
                    Horde::url('list.php', true)->redirect();
                }
            }
        }

        if ($prefs->isLocked('default_tasklist') ||
            count($this->_getTasklists()) <= 1) {
            $info['tasklist_id'] = $info['old_tasklist'] = Nag::getDefaultTasklist(Horde_Perms::EDIT);
        }
        try {
            $share = $nag_shares->getShare($info['tasklist_id']);
        } catch (Horde_Share_Exception $e) {
            $notification->push(sprintf(_("Access denied saving task: %s"), $e->getMessage()), 'horde.error');
            Horde::url('list.php', true)->redirect();
        }
        if (!$share->hasPermission($registry->getAuth(), Horde_Perms::EDIT)) {
            $notification->push(_("Access denied saving task to this task list."), 'horde.error');
            Horde::url('list.php', true)->redirect();
        }

        /* If a task id is set, we're modifying an existing task.  Otherwise,
         * we're adding a new task with the provided attributes. */
        if (!empty($info['task_id']) && !empty($info['old_tasklist'])) {
            $storage = $this->getInjector()
                ->getInstance('Nag_Factory_Driver')
                ->create($info['old_tasklist']);
            $info['tasklist'] = $info['tasklist_id'];
            try {
                $storage->modify($info['task_id'], $info);
            } catch (Nag_Exception $e) {
                $notification->push(sprintf(_("There was a problem saving the task: %s."), $e->getMessage()), 'horde.error');
                Horde::url('list.php', true)->redirect();
            }
        } else {
            /* Check permissions. */
            $perms = $this->getInjector()->getInstance('Horde_Core_Perms');
            if ($perms->hasAppPermission('max_tasks') !== true &&
                $perms->hasAppPermission('max_tasks') <= Nag::countTasks()) {
                Horde::url('list.php', true)->redirect();
            }

            /* Creating a new task. */
            $storage = $this->getInjector()
                ->getInstance('Nag_Factory_Driver')
                ->create($info['tasklist_id']);
            // These must be unset since the form sets them to NULL
            unset($info['owner']);
            unset($info['uid']);
            try {
              $newid = $storage->add($info);
            } catch (Nag_Exception $e) {
                $notification->push(sprintf(_("There was a problem saving the task: %s."), $e->getMessage()), 'horde.error');
                Horde::url('list.php', true)->redirect();
            }
        }

        $notification->push(sprintf(_("Saved %s."), $info['name']), 'horde.success');

        /* Return to the last page or to the task list. */
        if ($vars->savenewbutton) {
            $url = Horde::url('task.php', true)->add(array(
                'actionID' => 'add_task',
                'tasklist_id' => $info['tasklist_id'],
                'parent' => $info['parent']));
        } else {
            if ($url = Horde::verifySignedUrl(Horde_Util::getFormData('url'))) {
                $url = Horde::url($url, true);
            } else {
                $url = Horde::url('list.php', true);
            }
        }

        $response->setRedirectUrl((string)$url);
    }

    /**
     * Return tasklists the current user has PERMS_EDIT on.
     * See Bug: 13837.
     *
     * @return array  A hash of tasklist objects.
     */
    protected function _getTasklists()
    {
        $tasklist_enums = array();
        $user = $GLOBALS['registry']->getAuth();
        foreach (Nag::listTasklists(false, Horde_Perms::SHOW, false) as $tl_id => $tl) {
            if (!$tl->hasPermission($user, Horde_Perms::EDIT)) {
                continue;
            }
            $tasklist_enums[$tl_id] = Nag::getLabel($tl);
        }

        return $tasklist_enums;
    }

}
