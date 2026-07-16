<?php

namespace App\Filament\Concerns;

use App\Models\StoreMedia;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;

trait ManagesMyStoreProducts
{
    use WithFileUploads;

    public $photo = null;

    public string $productName = '';

    public string $productDescription = '';

    public string $search = '';

    public ?int $editingId = null;

    public bool $showForm = false;

    public function getMyProductsProperty(): Collection
    {
        $user = auth()->user();

        if (! $user) {
            return collect();
        }

        $q = StoreMedia::query()
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->id)
            ->where('kind', 'my_product')
            ->orderBy('sort_order')
            ->orderByDesc('id');

        $term = trim($this->search);
        if ($term !== '') {
            $like = '%'.$term.'%';
            $q->where(function ($query) use ($like) {
                $query->where('title', 'like', $like)
                    ->orWhere('description', 'like', $like);
            });
        }

        return $q->get();
    }

    public function getEditingItemProperty(): ?StoreMedia
    {
        if (! $this->editingId) {
            return null;
        }

        return $this->findOwnedProduct($this->editingId);
    }

    public function getProductsCountProperty(): int
    {
        $user = auth()->user();
        if (! $user) {
            return 0;
        }

        return (int) StoreMedia::query()
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->id)
            ->where('kind', 'my_product')
            ->count();
    }

    public function getVisibleCountProperty(): int
    {
        $user = auth()->user();
        if (! $user) {
            return 0;
        }

        return (int) StoreMedia::query()
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->id)
            ->where('kind', 'my_product')
            ->where('is_active', true)
            ->count();
    }

    public function openCreateForm(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function openEditForm(int $id): void
    {
        $item = $this->findOwnedProduct($id);
        if (! $item) {
            Notification::make()->danger()->title('المنتج غير موجود')->send();

            return;
        }

        $this->resetValidation();
        $this->resetErrorBag();
        $this->editingId = $item->id;
        $this->productName = (string) ($item->title ?? '');
        $this->productDescription = (string) ($item->description ?? '');
        $this->photo = null;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function saveProduct(): void
    {
        $rules = [
            'productName' => ['required', 'string', 'max:255'],
            'productDescription' => ['nullable', 'string', 'max:2000'],
        ];

        if (! $this->editingId) {
            $rules['photo'] = ['required', 'image', 'max:10240'];
        } else {
            $rules['photo'] = ['nullable', 'image', 'max:10240'];
        }

        $this->validate($rules, [
            'productName.required' => 'اكتب اسم المنتج',
            'photo.required' => 'أضف صورة للمنتج',
            'photo.image' => 'الملف يجب أن يكون صورة',
            'photo.max' => 'الحد الأقصى للصورة 10 ميجا',
        ]);

        $user = auth()->user();

        if ($this->editingId) {
            $item = $this->findOwnedProduct($this->editingId);
            if (! $item) {
                Notification::make()->danger()->title('المنتج غير موجود')->send();

                return;
            }

            $data = [
                'title' => trim($this->productName),
                'description' => trim($this->productDescription) !== '' ? trim($this->productDescription) : null,
            ];

            if ($this->photo) {
                if ($item->file_path) {
                    Storage::disk('public')->delete($item->file_path);
                }
                $data['file_path'] = $this->photo->store('store_media/my-products', 'public');
            }

            $item->update($data);
            Notification::make()->success()->title('تم تحديث المنتج')->send();
        } else {
            $path = $this->photo->store('store_media/my-products', 'public');
            $sort = (int) StoreMedia::query()
                ->where('owner_type', $user->getMorphClass())
                ->where('owner_id', $user->id)
                ->where('kind', 'my_product')
                ->max('sort_order');

            $user->storeMedia()->create([
                'kind' => 'my_product',
                'file_path' => $path,
                'title' => trim($this->productName),
                'description' => trim($this->productDescription) !== '' ? trim($this->productDescription) : null,
                'sort_order' => $sort + 1,
                'is_active' => true,
            ]);

            Notification::make()->success()->title('تمت إضافة المنتج للمعرض')->send();
        }

        $this->closeForm();
    }

    public function deleteProduct(int $id): void
    {
        $item = $this->findOwnedProduct($id);
        if (! $item) {
            return;
        }

        $item->delete();
        Notification::make()->success()->title('تم حذف المنتج')->send();
    }

    public function toggleActive(int $id): void
    {
        $item = $this->findOwnedProduct($id);
        if (! $item) {
            return;
        }

        $item->update(['is_active' => ! $item->is_active]);
        Notification::make()
            ->success()
            ->title($item->fresh()->is_active ? 'المنتج ظاهر في المعرض' : 'تم إخفاء المنتج من المعرض')
            ->send();
    }

    protected function findOwnedProduct(int $id): ?StoreMedia
    {
        $user = auth()->user();

        return StoreMedia::query()
            ->whereKey($id)
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->id)
            ->where('kind', 'my_product')
            ->first();
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->productName = '';
        $this->productDescription = '';
        $this->photo = null;
        $this->resetValidation();
        $this->resetErrorBag();
    }
}
