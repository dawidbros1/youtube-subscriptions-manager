<?php

declare (strict_types = 1);

namespace App\Controller;

use App\Model\Category;
use Phantom\Controller\AbstractController;
use Phantom\Helper\Request;
use Phantom\Helper\Session;
use Phantom\View;

class CategoryController extends AbstractController
{
    private $youtube;
    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->model = new Category();
        $this->youtube = $this->google->getYoutubeService();
        $this->forLogged();
    }

    # Method shows all subscriptions and subscriptions for current category
    public function listAction()
    {
        View::set("Moje grupa", 'category/list');

        $category = $this->search(null, true); // get current category with channels
        $channelsFromCategory = $this->youtube->toggleChannels($category->getChannels()); // returns class channel from YouTube

        // remove channels in user->categories from all subscriptions
        $subscriptions = $this->getSubscriptions();
        $ids = array_column($this->user->getCategories(), 'id');
        $channels = $this->model->channelsFromCategoryIds($ids);
        $subscriptions = $this->difference($subscriptions, $channels);

        return $this->render("category/list", [
            'category' => $category,
            'channelsFromCategory' => $this->sortChannels($category, $channelsFromCategory),
            'subscriptions' => $subscriptions,
            'channels' => $category->getChannels(),
        ]);
    }

    # Method create category => ONLY POST => Form in layout (main) in sidebar
    public function createAction()
    {
        if ($name = $this->request->isPost(['name'])) {
            $this->model->setArray([
                'name' => $name,
                'user' => $this->user->getId(),
            ]);

            if ($this->model->create()) {
                Session::success("Grupa została dodana");
            }
        }

        $this->redirectToLastPage();
    }

    # Method edit group name => ONLY POST => Form in category.manage
    public function editAction()
    {
        if ($name = $this->request->isPost(['name'])) {
            $category = $this->search();
            $category->set('name', $name);
            $category->update(['name']);
        }

        return $this->redirect('category.manage');
    }

    # Here we can edit and delete categories || go in category to adds subs to group
    public function manageAction()
    {
        View::set("Panel zarządzania grupami", 'category/manage');
        return $this->render('category/manage');
    }

    # Method shows videos from single group
    public function showAction()
    {
        $flow = $this->request->getParam('flow', 'grid') == "grid" ? "grid" : "list";
        $category = $this->search(null, true);
        View::set($category->get('name'), 'category/show');
        $videos = $this->youtube->listVideos($category->getChannels());

        return $this->render('category/show/' . $flow, [
            'category' => $category,
            'videos' => $this->sortVideoByDate($videos),

            'baseVideoUrl' => "https://www.youtube.com/watch?v=",
        ]);
    }

    # Method deletes category with channels
    public function deleteAction()
    {
        if ($id = $this->request->isPost(['id'])) {
            $category = $this->search($id);
            $category->delete();
        }

        return $this->redirect("category.manage");
    }

    # Method returns category by id
    private function search($id = null, $relation = false)
    {
        $id = $id ?? $this->request->getParam('id');
        $categories = $this->user->getCategories();
        $index = array_search($id, array_column($categories, 'id'));

        if ($index === false) {
            Session::error("Podana grupa nie istnieje");
            $this->redirect("home", [], true);
        }

        $category = $categories[$index];

        if ($relation == true) {
            $category->loadChannels();
        }

        return $category;
    }

    # Method returns all subscriptions for logged in user
    private function getSubscriptions()
    {
        $items = [];
        $subscriptions = $this->youtube->listSubscriptions();

        while ($subscriptions->nextPageToken != null) {
            $items = array_merge($items, $subscriptions->items);
            $pageToken = $subscriptions->nextPageToken;
            $subscriptions = $this->youtube->listSubscriptions($pageToken);
        }

        return array_merge($items, $subscriptions->items);
    }

    # Method removes from all subscriptions, subscription which are in our categories
    private function difference($subscriptions, $channels)
    {
        $ids = array_column($channels, 'channelId');

        foreach ($subscriptions as $key => $item) {
            if (in_array($item->snippet->resourceId->channelId, $ids)) {
                unset($subscriptions[$key]);
            }
        }

        return $subscriptions;
    }

    # Method sorts channels
    private function sortChannels($category, $channels)
    {
        $localChannels = $category->getChannels();
        $ids = array_column($localChannels, 'channelId');
        $output = [];

        for ($i = 0; $i < count($channels->items); $i++) {
            foreach ($channels->items as $channel) {
                if ($channel->id == $ids[$i]) {
                    $output[] = $channel;
                }
            }
        }

        return $output;
    }

    # Method sorts videos by date
    private function sortVideoByDate(array $videos = [])
    {
        for ($i = 0; $i < count($videos); $i++) {
            for ($j = 0; $j < count($videos) - 1 - $i; $j++) {
                $current = $videos[$j]->snippet->publishTime;
                $next = $videos[$j + 1]->snippet->publishTime;

                if ($current < $next) {
                    list($videos[$j], $videos[$j + 1]) = array($videos[$j + 1], $videos[$j]);
                }
            }
        }

        return $videos;
    }
}
