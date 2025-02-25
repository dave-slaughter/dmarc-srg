<?php

/**
 * dmarc-srg - A php parser, viewer and summary report generator for incoming DMARC reports.
 * Copyright (C) 2023 Aleksey Andreev (liuch)
 *
 * Available at:
 * https://github.com/liuch/dmarc-srg
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of  MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * =========================
 *
 * This script returns the users information
 *
 * HTTP GET query:
 *   when the header 'Accept' is 'application/json':
 *     It returns a list of the users or data for the user specified in the parameter user.
 *   otherwise:
 *     it returns the content of the index.html file
 * HTTP POST query:
 *   Inserts or updates data for specified user. The data must be in json format with the following fields:
 *     `name`        string  User name.
 *     `action`      string  Must be one of the following values: `add`, `update`, `delete`, `set_password`
 *                           If the value is `delete`, all the fields below will be ignored.
 *     `level`       integer One of the User::LEVEL_* values
 *     `enabled`     boolean Set `false` to temporarily deactivate the user
 *   Example:
 *     { "name": "user194", "action": "update", "enabled": true }
 * Other HTTP methods:
 *   It returns an error
 *
 * All the data is in json format.
 *
 * @category Web
 * @package  DmarcSrg
 * @author   Aleksey Andreev (liuch)
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU/GPLv3
 */

namespace Liuch\DmarcSrg;

use Liuch\DmarcSrg\Users\User;
use Liuch\DmarcSrg\Users\DbUser;
use Liuch\DmarcSrg\Users\UserList;
use Liuch\DmarcSrg\Exception\SoftException;
use Liuch\DmarcSrg\Exception\RuntimeException;

require 'init.php';

if (Core::isJson()) {
    try {
        $core = Core::instance();

        if (Core::method() == 'GET') {
            $core->auth()->isAllowed(User::LEVEL_USER);

            $uname = $_GET['user'] ?? '';
            if ($core->user()->name() === $uname && $core->user()->level() < User::LEVEL_ADMIN) {
                $udata = (new DbUser($uname))->toArray();
                Core::sendJson([
                    'name' =>     $udata['name'],
                    'level' =>    $data['level'],
                    'password' => $udata['password'] // bool value
                ]);
                return;
            }

            $core->auth()->isAllowed(User::LEVEL_ADMIN);

            if (!empty($uname)) {
                Core::sendJson((new DbUser($uname))->toArray());
                return;
            }

            $list = array_map(function ($user) {
                return $user->toArray();
            }, (new UserList())->getList()['users']);

            Core::sendJson([
                'users' => $list,
                'more'  => false
            ]);
            return;
        } elseif (Core::method() == 'POST') {
            $data = Core::getJsonData();
            if ($data) {
                $uname = $data['name'] ?? null;
                $action = $data['action'] ?? '';
                if ($action === 'set_password') {
                    $core->auth()->isAllowed(User::LEVEL_USER);

                    $user = new DbUser($uname);
                    $c_user = $core->user();
                    if ($c_user->name() === $user->name()) {
                        if (!$c_user->verifyPassword($data['password'] ?? '')) {
                            throw new SoftException('The current password is incorrect');
                        }
                    } else {
                        $core->auth()->isAllowed(User::LEVEL_ADMIN);
                        if ($c_user->level() <= $user->level()) {
                            throw new ForbiddenException('Forbidden');
                        }
                    }
                    if (empty($data['new_password'])) {
                        throw new SoftException('New password must not be empty');
                    }
                    $user->setPassword($data['new_password']);
                    Core::sendJson([
                        'error_code' => 0,
                        'message'    => 'The password has been successfully updated'
                    ]);
                    return;
                }

                $core->auth()->isAllowed(User::LEVEL_ADMIN);

                if (!empty($data['level']) && gettype($data['level']) == 'string') {
                    $data['level'] = User::stringToLevel($data['level']);
                }
                $user = new DbUser([
                    'name'    => $uname,
                    'level'   => $data['level'] ?? null,
                    'enabled' => $data['enabled'] ?? null
                ]);
                $check_level = function () use ($core, $user, $action) {
                    if ($core->user()->level() <= $user->level()) {
                        throw new SoftException("Insufficient access level to {$action} this user");
                    }
                };
                switch ($action) {
                    case 'add':
                        $check_level();
                        if ($user->exists()) {
                            throw new SoftException('The user already exists');
                        }
                        $user->save();
                        break;
                    case 'update':
                        $check_level();
                        if (!$user->exists()) {
                            throw new SoftException('The user does not exists');
                        }
                        $user->save();
                        break;
                    case 'delete':
                        $check_level();
                        $user->delete();
                        unset($user);
                        break;
                    default:
                        throw new SoftException(
                            'Unknown action. Valid values are "add", "update", "delete", "set_password".'
                        );
                }

                $res = [
                    'error_code' => 0,
                    'message'    => 'Successfully'
                ];
                if (isset($user)) {
                    $res['user'] = $user->toArray();
                }
                Core::sendJson($res);
                return;
            }
        }
    } catch (RuntimeException $e) {
        Core::sendJson(ErrorHandler::exceptionResult($e));
        return;
    }
} elseif (Core::method() == 'GET') {
    Core::instance()->sendHtml();
    return;
}

Core::sendBad();
