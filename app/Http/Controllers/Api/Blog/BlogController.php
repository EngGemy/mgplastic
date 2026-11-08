<?php

namespace App\Http\Controllers\Api\Blog;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\BlogLike;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BlogController extends Controller
{
    // Create Blog (Plumber or Admin) - status pending if plumber
    public function store(Request $request)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated'], 401);
        }

        if (! in_array($user->role, ['admin', 'super_admin', 'plumber'], true)) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'category_id' => 'required|exists:blog_categories,id',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'image' => 'nullable|image|max:2048',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('blogs', 'public');
        }

        $status = $user->isAdminUser() ? 'approved' : 'pending';

        $blog = Blog::create([
            'category_id' => $request->category_id,
            'user_id' => $user->id,
            'title' => $request->title,
            'description' => $request->description,
            'image' => $imagePath,
            'status' => $status,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Blog created',
            'data' => $this->blogPayload($blog->load(['category', 'author'])),
        ]);
    }

    // Approve blog (Admin only)
    public function approve($id)
    {
        $blog = Blog::findOrFail($id);
        $user = Auth::user();
        if (! $user || ! $user->isAdminUser()) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        }
        $blog->status = 'approved';
        $blog->save();

        return response()->json(['status' => true, 'message' => 'Blog approved']);
    }

    // List blogs (only approved for public)
    public function index()
    {
        $blogs = Blog::with(['category', 'author'])
            ->where('status', 'approved')
            ->latest()
            ->paginate(30);

        return response()->json([
            'status' => true,
            'data' => $blogs->getCollection()->map(fn (Blog $blog) => $this->blogPayload($blog))->values(),
            'meta' => [
                'current_page' => $blogs->currentPage(),
                'last_page' => $blogs->lastPage(),
                'per_page' => $blogs->perPage(),
                'total' => $blogs->total(),
            ],
        ]);
    }

    // Show single blog
    public function show($id)
    {
        $blog = Blog::with(['category', 'author', 'comments.user'])->where('status', 'approved')->findOrFail($id);

        return response()->json([
            'status' => true,
            'data' => $this->blogPayload($blog),
        ]);
    }

    // Like or Unlike blog
    public function like($id)
    {
        $blog = Blog::findOrFail($id);
        $like = BlogLike::where('blog_id', $id)->where('user_id', Auth::id())->first();

        if ($like) {
            $like->delete();

            return response()->json(['status' => true, 'message' => 'Like removed']);
        } else {
            BlogLike::create(['blog_id' => $id, 'user_id' => Auth::id()]);

            return response()->json(['status' => true, 'message' => 'Blog liked']);
        }
    }

    // Add comment
    public function comment(Request $request, $id)
    {
        $request->validate(['comment' => 'required|string|max:500']);
        $blog = Blog::findOrFail($id);

        $comment = BlogComment::create([
            'blog_id' => $blog->id,
            'user_id' => Auth::id(),
            'comment' => $request->comment,
        ]);

        return response()->json(['status' => true, 'message' => 'Comment added', 'data' => $comment]);
    }

    /**
     * @return array<string, mixed>
     */
    private function blogPayload(Blog $blog): array
    {
        $payload = $blog->toArray();
        $payload['description'] = api_plain_text($blog->description);

        return $payload;
    }
}
