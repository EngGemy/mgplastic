<?php

namespace App\Http\Controllers\Api\Blog;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Blog;
use App\Models\BlogLike;
use App\Models\BlogComment;

class BlogController extends Controller
{
    // Create Blog (Plumber or Admin) - status pending if plumber
    public function store(Request $request)
    {
        $request->validate([
            'category_id' => 'required|exists:blog_categories,id',
            'title'       => 'required|string|max:255',
            'description' => 'required|string',
            'image'       => 'nullable|image|max:2048'
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('blogs', 'public');
        }

        $status = Auth::user()->role === 'admin' ? 'approved' : 'pending';

        $blog = Blog::create([
            'category_id' => $request->category_id,
            'user_id'     => Auth::id(),
            'title'       => $request->title,
            'description' => $request->description,
            'image'       => $imagePath,
            'status'      => $status
        ]);

        return response()->json(['status' => true, 'message' => 'Blog created', 'data' => $blog]);
    }

    // Approve blog (Admin only)
    public function approve($id)
    {
        $blog = Blog::findOrFail($id);
        if (Auth::user()->role !== 'admin') {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        }
        $blog->status = 'approved';
        $blog->save();

        return response()->json(['status' => true, 'message' => 'Blog approved']);
    }

    // List blogs (only approved for public)
    public function index()
    {
        $blogs = Blog::with(['category', 'author'])->where('status', 'approved')->latest()->get();
        return response()->json(['status' => true, 'data' => $blogs]);
    }

    // Show single blog
    public function show($id)
    {
        $blog = Blog::with(['category', 'author', 'comments.user'])->where('status', 'approved')->findOrFail($id);
        return response()->json(['status' => true, 'data' => $blog]);
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
            'comment' => $request->comment
        ]);

        return response()->json(['status' => true, 'message' => 'Comment added', 'data' => $comment]);
    }
}
