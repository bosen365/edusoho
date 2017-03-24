<?php

namespace AppBundle\Controller\Course;

use AppBundle\Common\ArrayToolkit;
use AppBundle\Controller\BaseController;
use Biz\Content\Service\FileService;
use Biz\Course\Service\CourseService;
use Biz\Course\Service\CourseSetService;
use Biz\OpenCourse\Service\OpenCourseService;
use Biz\Task\Service\TaskService;
use Biz\Taxonomy\Service\TagService;
use Symfony\Component\HttpFoundation\Request;

class CourseSetManageController extends BaseController
{
    public function createAction(Request $request)
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            if (!isset($data['type'])) {
                throw $this->createNotFoundException('未设置课程类型');
            } else {
                $type = $data['type'];
            }

            if (in_array($type, array('open', 'liveOpen'))) {
                $openCourse = $this->getOpenCourseService()->createCourse($data);

                return $this->redirectToRoute(
                    'open_course_manage',
                    array(
                        'id' => $openCourse['id'],
                    )
                );
            } else {
                $courseSet = $this->getCourseSetService()->createCourseSet($data);

                return $this->redirect(
                    $this->generateUrl(
                        'course_set_manage_base',
                        array(
                            'id' => $courseSet['id'],
                        )
                    )
                );
            }
        }

        $user = $this->getUser();
        $userProfile = $this->getUserService()->getUserProfile($user->getId());
        $user = $this->getUserService()->getUser($user->getId());

        return $this->render(
            'courseset-manage/create.html.twig',
            array(
                'user' => $user,
                'userProfile' => $userProfile,
            )
        );
    }

    public function indexAction($id)
    {
        $courseSet = $this->getCourseSetService()->tryManageCourseSet($id);
        if ($courseSet['locked']) {
            return $this->redirectToRoute(
                'course_set_manage_sync',
                array(
                    'id' => $id,
                    'sideNav' => 'tasks',
                )
            );
        }

        return $this->redirectToRoute(
            'course_set_manage_courses',
            array(
                'courseSetId' => $id,
            )
        );
    }

    public function headerAction($courseSet, $course = null)
    {
        // $users = empty($courseSet['teacherIds']) ? array() : $this->getUserService()->findUsersByIds($courseSet['teacherIds']);
        //暂时显示课程的创建者
        if (empty($courseSet['teacherIds'])) {
            $courseSet['teacherIds'] = array($courseSet['creator']);
        }
        $users = $this->getUserService()->findUsersByIds($courseSet['teacherIds']);

        return $this->render(
            'courseset-manage/header.html.twig',
            array(
                'courseSet' => $courseSet,
                'course' => $course,
                'users' => $users,
            )
        );
    }

    public function sidebarAction($courseSetId, $curCourse, $sideNav)
    {
        $user = $this->getCurrentUser();

        $courses = $this->getCourseService()->findCoursesByCourseSetId($courseSetId);

        if (!$user->isAdmin()) {
            $courses = array_filter(
                $courses,
                function ($course) use ($user) {
                    return in_array($user->getId(), $course['teacherIds']);
                }
            );
        }

        if (empty($curCourse)) {
            $curCourse = $this->getCourseService()->getDefaultCourseByCourseSetId($courseSetId);
        }
        if (empty($curCourse) && !empty($courses)) {
            $curCourse = current($courses);
        }

        $tasks = $this->getTaskService()->findTasksByCourseId($curCourse['id']);

        $hasLiveTasks = ArrayToolkit::some($tasks, function ($task) {
            return $task['type'] === 'live';
        });

        $courseSet = $this->getCourseSetService()->getCourseSet($courseSetId);

        return $this->render(
            'courseset-manage/sidebar.html.twig',
            array(
                'courseSet' => $courseSet,
                'curCourse' => $curCourse,
                'courses' => $courses,
                'side_nav' => $sideNav,
                'hasLiveTasks' => $hasLiveTasks,
            )
        );
    }

    //基础信息
    public function baseAction(Request $request, $id)
    {
        $courseSet = $this->getCourseSetService()->tryManageCourseSet($id);

        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            $this->getCourseSetService()->updateCourseSet($id, $data);
            $this->setFlashMessage('success', '课程基本信息已保存！');

            return $this->redirect($this->generateUrl('course_set_manage_base', array('id' => $id)));
        }

        if ($courseSet['locked']) {
            return $this->redirectToRoute(
                'course_set_manage_sync',
                array(
                    'id' => $id,
                    'sideNav' => 'base',
                )
            );
        }

        $tags = array();
        if (!empty($courseSet['tags'])) {
            $tags = $this->getTagService()->findTagsByIds($courseSet['tags']);
        }

        return $this->render(
            'courseset-manage/base.html.twig',
            array(
                'courseSet' => $courseSet,
                'tags' => ArrayToolkit::column($tags, 'name'),
            )
        );
    }

    public function detailAction(Request $request, $id)
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            if (!empty($data['goals'])) {
                $data['goals'] = json_decode($data['goals'], true);
            }
            if (!empty($data['audiences'])) {
                $data['audiences'] = json_decode($data['audiences'], true);
            }

            $this->getCourseSetService()->updateCourseSetDetail($id, $data);
            $this->setFlashMessage('success', '课程详细信息已保存！');

            return $this->redirect($this->generateUrl('course_set_manage_detail', array('id' => $id)));
        }

        $courseSet = $this->getCourseSetService()->tryManageCourseSet($id);

        if ($courseSet['locked']) {
            return $this->redirectToRoute(
                'course_set_manage_sync',
                array(
                    'id' => $id,
                    'sideNav' => 'detail',
                )
            );
        }

        return $this->render(
            'courseset-manage/detail.html.twig',
            array(
                'courseSet' => $courseSet,
            )
        );
    }

    public function coverAction(Request $request, $id)
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            $this->getCourseSetService()->changeCourseSetCover($id, $data);

            return $this->redirect($this->generateUrl('course_set_manage_cover', array('id' => $id)));
        }

        $courseSet = $this->getCourseSetService()->tryManageCourseSet($id);
        // if ($courseSet['cover']) {
        //     $courseSet['cover'] = json_decode($courseSet['cover'], true);
        // }

        if ($courseSet['locked']) {
            return $this->redirectToRoute(
                'course_set_manage_sync',
                array(
                    'id' => $id,
                    'sideNav' => 'cover',
                )
            );
        }

        return $this->render(
            'courseset-manage/cover.html.twig',
            array(
                'courseSet' => $courseSet,
            )
        );
    }

    public function coverCropAction(Request $request, $id)
    {
        $courseSet = $this->getCourseSetService()->tryManageCourseSet($id);

        if ($request->getMethod() == 'POST') {
            $data = $request->request->all();
            $this->getCourseSetService()->changeCourseSetCover($courseSet['id'], json_decode($data['images'], true));

            return $this->redirect($this->generateUrl('course_set_manage_cover', array('id' => $courseSet['id'])));
        }

        if ($courseSet['locked']) {
            return $this->redirectToRoute(
                'course_set_manage_sync',
                array(
                    'id' => $id,
                    'sideNav' => 'cover',
                )
            );
        }

        $fileId = $request->getSession()->get('fileId');

        list($pictureUrl, $naturalSize, $scaledSize) = $this->getFileService()->getImgFileMetaInfo($fileId, 480, 270);

        return $this->render(
            'courseset-manage/cover-crop.html.twig',
            array(
                'courseSet' => $courseSet,
                'pictureUrl' => $pictureUrl,
                'naturalSize' => $naturalSize,
                'scaledSize' => $scaledSize,
            )
        );
    }

    public function deleteAction($id)
    {
        try {
            $this->getCourseSetService()->deleteCourseSet($id);

            return $this->createJsonResponse(array('success' => true));
        } catch (\Exception $e) {
            return $this->createJsonResponse(array('success' => false, 'message' => $e->getMessage()));
        }
    }

    public function publishAction($id)
    {
        try {
            $courseSet = $this->getCourseSetService()->getCourseSet($id);

            if ($courseSet['type'] == 'live') {
                $course = $this->getCourseService()->getDefaultCourseByCourseSetId($courseSet['id']);

                if (empty($course['maxStudentNum'])) {
                    throw $this->createAccessDeniedException('直播课程发布前需要在计划设置中设置课程人数');
                }

                $this->getCourseService()->publishCourse($course['id']);
            }
            $this->getCourseSetService()->publishCourseSet($id);

            return $this->createJsonResponse(array('success' => true));
        } catch (\Exception $e) {
            return $this->createJsonResponse(array('success' => false, 'message' => $e->getMessage()));
        }
    }

    public function closeAction($id)
    {
        try {
            $this->getCourseSetService()->closeCourseSet($id);

            return $this->createJsonResponse(array('success' => true));
        } catch (\Exception $e) {
            return $this->createJsonResponse(array('success' => false, 'message' => $e->getMessage()));
        }
    }

    public function syncInfoAction(Request $request, $id)
    {
        $sideNav = $request->query->get('sideNav', '');
        $courseSet = $this->getCourseSetService()->tryManageCourseSet($id);

        $courses = $this->getCourseService()->findCoursesByCourseSetId($id);

        $menuPath = '';
        $menuTitle = '';

        //同步的课程不允许操作的菜单列表
        $lockedCourseSetMenus = array(
            'base' => '基本信息',
            'detail' => '详细信息',
            'cover' => '课程封面',
            'question' => '题目管理',
            'testpaper' => '试卷管理',
            'files' => '课程文件',
        );
        $lockedCourseMenus = array(
            'tasks' => '计划任务',
            'info' => '计划设置',
            'replay' => '录播管理',
            'marketing' => '营销设置',
            'teachers' => '教师设置',
        );
        if (!empty($lockedCourseSetMenus[$sideNav])) {
            if (!$courseSet['locked']) {
                $menuPath = $this->generateUrl('course_set_manage_'.$sideNav, array('id' => $courseSet['id']));
            } else {
                $menuPath = $this->generateUrl('course_set_manage_'.$sideNav, array('id' => $courseSet['parentId']));
            }
            $menuTitle = $lockedCourseSetMenus[$sideNav];
        } elseif (!empty($lockedCourseMenus[$sideNav])) {
            if (!$courseSet['locked']) {
                $menuPath = $this->generateUrl(
                    'course_set_manage_course_'.$sideNav,
                    array('courseSetId' => $courseSet['id'], 'courseId' => $courses[0]['id'])
                );
            } else {
                $menuPath = $this->generateUrl(
                    'course_set_manage_course_'.$sideNav,
                    array('courseSetId' => $courseSet['parentId'], 'courseId' => $courses[0]['parentId'])
                );
            }
            $menuTitle = $lockedCourseMenus[$sideNav];
        } else {
            throw new \Exception('Invalid Menu Key');
        }

        if (!$courseSet['locked']) {
            return $this->redirect($menuPath);
        }

        $copyCourseSet = $this->getCourseSetService()->getCourseSet($courseSet['parentId']);

        return $this->render(
            'courseset-manage/locked.html.twig',
            array(
                'id' => $id,
                'sideNav' => $sideNav,
                'courseSet' => $courseSet,
                'copyCourseSet' => $copyCourseSet,
                'menuPath' => $menuPath,
                'menuTitle' => $menuTitle,
            )
        );
    }

    public function unlockConfirmAction($id)
    {
        $this->getCourseSetService()->tryManageCourseSet($id);

        return $this->render(
            'courseset-manage/unlock-confirm.html.twig',
            array(
                'id' => $id,
            )
        );
    }

    public function unlockAction($id)
    {
        try {
            $this->getCourseSetService()->unlockCourseSet($id);

            return $this->createJsonResponse(array('success' => true));
        } catch (\Exception $e) {
            return $this->createJsonResponse(array('success' => false, 'message' => $e->getMessage()));
        }
    }

    /**
     * @return CourseService
     */
    protected function getCourseService()
    {
        return $this->createService('Course:CourseService');
    }

    /**
     * @return CourseSetService
     */
    protected function getCourseSetService()
    {
        return $this->createService('Course:CourseSetService');
    }

    /**
     * @return TagService
     */
    protected function getTagService()
    {
        return $this->createService('Taxonomy:TagService');
    }

    /**
     * @return FileService
     */
    protected function getFileService()
    {
        return $this->createService('Content:FileService');
    }

    /**
     * @return TaskService
     */
    protected function getTaskService()
    {
        return $this->createService('Task:TaskService');
    }

    /**
     * @return OpenCourseService
     */
    protected function getOpenCourseService()
    {
        return $this->createService('OpenCourse:OpenCourseService');
    }
}