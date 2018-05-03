<?php

/*
 * This file is part of the Pho package.
 *
 * (c) Emre Sokullu <emre@phonetworks.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

 namespace GraphJS\Controllers;

use CapMousse\ReactRestify\Http\Request;
use CapMousse\ReactRestify\Http\Response;
use CapMousse\ReactRestify\Http\Session;
use Pho\Kernel\Kernel;
use Valitron\Validator;
use PhoNetworksAutogenerated\User;
use PhoNetworksAutogenerated\Thread;
use PhoNetworksAutogenerated\UserOut\Start;
use PhoNetworksAutogenerated\UserOut\Reply;
use Pho\Lib\Graph\ID;
use Pho\Lib\Graph\TailNode;



/**
 * Takes care of Forum
 * 
 * @author Emre Sokullu <emre@phonetworks.org>
 */
class ForumController extends AbstractController
{

    public function delete(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        if(is_null($id = $this->dependOnSession(...\func_get_args()))) {
            return;
        }
        $data = $request->getQueryParams();
        $v = new Validator($data);
        $v->rule('required', ['id']);
        if(!$v->validate()) {
            $this->fail($response, "Entity ID unavailable.");
            return;
        }
        $entity = $kernel->gs()->entity($data["id"]);
        $deleted = [];
        if($entity instanceof Thread) {
            if($entity->edges()->in(Start::class)->current()->tail()->id()->toString()==$id) {
                $deleted[] = (string) $entity->id();
                // replies automatically deleted
                $entity->destroy();
                return $this->succeed($response, [
                    "deleted" => $deleted
                ]);
            }
            return $this->fail($response, "You are not the owner of this thread.");
        }
        elseif($entity instanceof Reply) {
            if($entity->tail()->id()->toString()==$id) {
                $deleted[] = (string) $entity->id();
                $entity->destroy();
                return $this->succeed($response, [
                    "deleted" => $deleted
                ]);
            }
            return $this->fail($response, "You are not the owner of this reply.");
        }
        
        $this->fail($response, "The ID does not belong to a thread or reply.");
    }

    /**
     * Start Forum Thread 
     * 
     * [title, message]
     * 
     * @param Request  $request
     * @param Response $response
     * @param Session  $session
     * @param Kernel   $kernel
     * @param string   $id
     * 
     * @return void
     */
    public function startThread(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        if(is_null($id = $this->dependOnSession(...\func_get_args()))) {
            return;
        }
        $data = $request->getQueryParams();
        $v = new Validator($data);
        $v->rule('required', ['title', 'message']);
        $v->rule('lengthMax', ['title'], 80);
        if(!$v->validate()) {
            $this->fail($response, "Title (up to 80 chars) and Message are required.");
            return;
        }
        $i = $kernel->gs()->node($id);
        $thread = $i->start($data["title"], $data["message"]);
        $this->succeed(
            $response, [
            "id" => (string) $thread->id()
            ]
        );
    }

    /**
     * Reply Forum Thread
     * 
     * [id, message]
     *
     * @param Request  $request
     * @param Response $response
     * @param Session  $session
     * @param Kernel   $kernel
     * 
     * @return void
     */
    public function replyThread(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        if(is_null($id = $this->dependOnSession(...\func_get_args()))) {
            return;
        }
        $data = $request->getQueryParams();
        $v = new Validator($data);
        $v->rule('required', ['id', 'message']);
        if(!$v->validate()) {
            $this->fail($response, "Thread ID and Message are required.");
            return;
        }
        $i = $kernel->gs()->node($id);
        $thread = $kernel->gs()->node($data["id"]);
        if(!$thread instanceof Thread) {
            $this->fail($response, "Given  ID is not associated with a forum thread.");
            return;
        }
        $reply = $i->reply($thread, $data["message"]);
        $this->succeed(
            $response, [
            "id" => (string) $reply->id()
            ]
        );
    }

 
    public function edit(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        if(is_null($id = $this->dependOnSession(...\func_get_args()))) {
            return;
        }
        $data = $request->getQueryParams();
        $v = new Validator($data);
        $v->rule('required', ['id', 'content']);
        if(!$v->validate()) {
            $this->fail($response, "Message ID and Content are required.");
            return;
        }
        $i = $kernel->gs()->node($id);
        $entity = $kernel->gs()->entity($data["id"]);
        if(!$entity instanceof Thread && !$entity instanceof Reply) {
            $this->fail($response, "Incompatible entity type.");
            return;
        }
        try {
        $i->edit($entity)->setContent($data["content"]);
        }
     catch(\Exception $e) {
        $this->fail($response, $e->getMessage());
            return;
     }
     $this->succeed($response);
    }

    /**
     * Get Threads
     * 
     * with number of replies
     *
     * @param Request  $request
     * @param Response $response
     * @param Session  $session
     * @param Kernel   $kernel
     * 
     * @return void
     */
    public function getThreads(Request $request, Response $response, Kernel $kernel)
    {
            
        $threads = [];
        $everything = $kernel->graph()->members();
        
        foreach($everything as $thing) {
            if($thing instanceof Thread) {
                $contributors_x = [];
                $contributors = array_map(
                    function(User $u) : array 
                {
                        return [ 
                            $u->id()->toString() =>
                                array_change_key_case(
                                    array_filter(
                                        $u->attributes()->toArray(), 
                                        function (string $key): bool {
                                            return strtolower($key) != "password";
                                        },
                                        ARRAY_FILTER_USE_KEY
                                    ), CASE_LOWER
                                )
                            ];
                },array_map( function(Reply $r): User {
                    return $r->tail()->node();
                }, $thing->getReplies()));
                foreach($contributors as $contributor) {
                    foreach($contributor as $k=>$v) {
                        if(!isset($contributors_x[$k]))
                            $contributors_x[$k] = $v;
                    }
                }
                unset($contributors);
                $threads[] = [
                    "id" => (string) $thing->id(),
                    "title" => $thing->getTitle(),
                    "author" => (string) $thing->edges()->in(Start::class)->current()->tail()->id(),
                    "timestamp" => (string) $thing->getCreateTime(),
                    "contributors" => $contributors_x
                ];
            }
        }
        $this->succeed(
            $response, [
            "threads" => $threads
            ]
        );
    }

    /**
     * Get Thread
     * 
     * [id]
     *
     * @param Request  $request
     * @param Response $response
     * @param Kernel   $kernel
     * 
     * @return void
     */
    public function getThread(Request $request, Response $response, Kernel $kernel)
    {
        $data = $request->getQueryParams();
        $v = new Validator($data);
        $v->rule('required', ['id']);
        if(!$v->validate()) {
            $this->fail($response, "Thread ID required.");
            return;
        }
        $thread = $kernel->gs()->node($data["id"]);
        if(!$thread instanceof Thread) {
            $this->fail($response, "Not a Thread");
        }
        $replies = $thread->getReplies();
        $this->succeed(
            $response, [
            "title" => $thread->getTitle(),
            "messages" => array_merge(
                [[
                    "id" => (string) $thread->id(),
                    "author" => (string) $thread->edges()->in()->current()->tail()->id(),
                    "content" => $thread->getContent(),
                    "timestamp" => (string) $thread->getCreateTime()
                ]],
                array_map(
                    function ($obj): array {
                        return [
                            "id" => (string) $obj->id(),
                            "author" => (string) $obj->tail()->id(),
                            "content" => $obj->getContent(),
                            "timestamp" => (string) $obj->getReplyTime()
                        ];
                    },
                    $replies
                )
            )
            ]
        );
    }
}
