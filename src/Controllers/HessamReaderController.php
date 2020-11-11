<?php

namespace WebDevEtc\BlogEtc\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Swis\Laravel\Fulltext\Search;
use WebDevEtc\BlogEtc\Captcha\UsesCaptcha;
use WebDevEtc\BlogEtc\Middleware\DetectLanguage;
use WebDevEtc\BlogEtc\Models\HessamCategory;
use WebDevEtc\BlogEtc\Models\HessamLanguage;
use WebDevEtc\BlogEtc\Models\HessamPost;
use WebDevEtc\BlogEtc\Models\HessamPostTranslation;

/**
 * Class HessamReaderController
 * All of the main public facing methods for viewing blog content (index, single posts)
 * @package WebDevEtc\BlogEtc\Controllers
 */
class HessamReaderController extends Controller
{
    use UsesCaptcha;

    public function __construct()
    {
        $this->middleware(DetectLanguage::class);
    }

    /**
     * Show blog posts
     * If category_slug is set, then only show from that category
     *
     * @param null $category_slug
     * @return mixed
     */
    public function index($locale, $category_slug = null, Request $request)
    {
        // the published_at + is_published are handled by BlogEtcPublishedScope, and don't take effect if the logged in user can manageb log posts

        //todo
        $title = 'Blog Page'; // default title...

        $categoryChain = null;
        if ($category_slug) {
            $category = HessamCategory::where("slug", $category_slug)->firstOrFail();
            $categoryChain = $category->getAncestorsAndSelf();
            $posts = $category->posts()->where("blog_etc_post_categories.blog_etc_category_id", $category->id);

            // at the moment we handle this special case (viewing a category) by hard coding in the following two lines.
            // You can easily override this in the view files.
            \View::share('blogetc_category', $category); // so the view can say "You are viewing $CATEGORYNAME category posts"
            $title = 'Posts in ' . $category->category_name . " category"; // hardcode title here...
        } else {
            $posts = HessamPostTranslation::query();
        }

//        $posts = $posts->where('is_published', '=', 1)
//            ->where('posted_at', '<', Carbon::now()->format('Y-m-d H:i:s'))
//            ->where('lang_id', $request->get("lang_id"))
//            ->orderBy("posted_at", "desc")
//            ->paginate(config("blogetc.per_page", 10));

        $posts = HessamPostTranslation::where('lang_id', $request->get("lang_id"))
            ->with(['post' => function($query){
            $query->where("is_published" , '=' , true);
            $query->where('posted_at', '<', Carbon::now()->format('Y-m-d H:i:s'));
            $query->orderBy("posted_at", "desc");
        }])->paginate(config("blogetc.per_page", 10));

        //load category hierarchy
        $rootList = HessamCategory::roots()->get();
        HessamCategory::loadSiblingsWithList($rootList);

        return view("blogetc::index", [
            'locale' => $request->get("locale"),
            'category_chain' => $categoryChain,
            'categories' => $rootList,
            'posts' => $posts,
            'title' => $title,
        ]);
    }

    /**
     * Show the search results for $_GET['s']
     *
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @throws \Exception
     */
    public function search(Request $request)
    {
        if (!config("blogetc.search.search_enabled")) {
            throw new \Exception("Search is disabled");
        }
        $query = $request->get("s");
        $search = new Search();
        $search_results = $search->run($query);

        \View::share("title", "Search results for " . e($query));

        $categories = HessamCategory::all();

        return view("blogetc::search", [
                'categories' => $categories,
                'query' => $query,
                'search_results' => $search_results]
        );

    }

    /**
     * View all posts in $category_slug category
     *
     * @param Request $request
     * @param $category_slug
     * @return mixed
     */
    public function view_category($hierarchy)
    {
        $categories = explode('/', $hierarchy);
        return $this->index(end($categories));
    }

    /**
     * View a single post and (if enabled) it's comments
     *
     * @param Request $request
     * @param $blogPostSlug
     * @return mixed
     */
    public function viewSinglePost(Request $request, $locale, $blogPostSlug)
    {
        // the published_at + is_published are handled by BlogEtcPublishedScope, and don't take effect if the logged in user can manage log posts
        $blog_post = HessamPostTranslation::where([
            ["slug", "=", $blogPostSlug],
            ['lang_id', "=" , $request->get("lang_id")]
        ])->firstOrFail();

        if ($captcha = $this->getCaptchaObject()) {
            $captcha->runCaptchaBeforeShowingPosts($request, $blog_post);
        }

        return view("blogetc::single_post", [
            'post' => $blog_post,
            // the default scope only selects approved comments, ordered by id
            'comments' => $blog_post->post->comments()
                ->with("user")
                ->get(),
            'captcha' => $captcha,
        ]);
    }

}
