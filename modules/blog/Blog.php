<?php
declare(strict_types=1);

/**
 * Public blog: longer writing from the shared Markdown post archive in modules/news/posts.
 */
class Blog extends Trongate {

    /**
     * blog: published writing posts, newest first.
     */
    public function index(): void {
        $this->view('index', [
            'posts' => Posts::all('writing'),
        ]);
    }

    /**
     * blog/{slug}: one writing post. Changelog entries redirect to news/{slug};
     * merged posts redirect to the section that replaced them.
     */
    public function show(): void {
        $slug = (string) segment(3);
        $post = Posts::find($slug, 'blog');
        if ($post === null) {
            $moved = Posts::moved($slug);
            if ($moved !== null) {
                header('Location: ' . BASE_URL . $moved, true, 301);
                return;
            }
            $this->not_found();
            return;
        }

        if ($post->kind !== 'writing') {
            header('Location: ' . BASE_URL . 'news/' . $post->slug, true, 301);
            return;
        }

        $this->view('show', ['post' => $post]);
    }

    /**
     * blog/feed: an Atom feed of blog (writing) posts.
     */
    public function feed(): void {
        $posts = array_slice(Posts::all('writing'), 0, 20);
        foreach ($posts as $post) {
            $post->html = Markdown::render($post->body);
        }

        header('Content-Type: application/atom+xml; charset=UTF-8');
        $this->view('feed', ['posts' => $posts]);
    }

    /**
     * The site's 404 page.
     */
    private function not_found(): void {
        $this->templates->error_404();
    }

}
