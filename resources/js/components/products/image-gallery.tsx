import { router, useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Star } from 'lucide-react';
import { useRef } from 'react';
import ConfirmButton from '@/components/confirm-button';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { destroy, store, update } from '@/routes/products/images';
import type { Product, ProductImage, ProductVariant } from '@/types';

type Props = {
    product: Product;
    variants: ProductVariant[];
    images: ProductImage[];
};

function UploadForm({ product, variants }: Omit<Props, 'images'>) {
    const inputRef = useRef<HTMLInputElement>(null);
    const form = useForm<{ image: File | null; product_variant_id: string }>({
        image: null,
        product_variant_id: '',
    });

    return (
        <form
            className="flex flex-wrap items-center gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.submit(store(product.id), {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.reset();

                        if (inputRef.current !== null) {
                            inputRef.current.value = '';
                        }
                    },
                });
            }}
        >
            <Input
                ref={inputRef}
                type="file"
                aria-label="Imagem"
                accept="image/jpeg,image/png,image/webp"
                className="max-w-xs"
                onChange={(event) =>
                    form.setData('image', event.target.files?.[0] ?? null)
                }
            />
            {product.type === 'variable' && (
                <NativeSelect
                    aria-label="Variação da imagem"
                    className="w-auto"
                    value={form.data.product_variant_id}
                    onChange={(event) =>
                        form.setData('product_variant_id', event.target.value)
                    }
                >
                    <option value="">Todas as variações</option>
                    {variants.map((variant) => (
                        <option key={variant.id} value={variant.id}>
                            {variant.label}
                        </option>
                    ))}
                </NativeSelect>
            )}
            <Button
                variant="outline"
                disabled={form.processing || form.data.image === null}
            >
                Enviar imagem
            </Button>
            <InputError
                className="w-full"
                message={form.errors.image ?? form.errors.product_variant_id}
            />
        </form>
    );
}

export default function ImageGallery({ product, variants, images }: Props) {
    const { can } = useTenant();
    const canManage = can('products.update');

    function change(
        image: ProductImage,
        data: Record<string, string | boolean>,
    ) {
        router.visit(update({ product: product.id, image: image.id }), {
            data,
            preserveScroll: true,
        });
    }

    return (
        <div className="space-y-4">
            {canManage && <UploadForm product={product} variants={variants} />}
            {images.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Nenhuma imagem cadastrada.
                </p>
            )}
            <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {images.map((image, position) => (
                    <li
                        key={image.id}
                        className="space-y-2 rounded-lg border p-2"
                    >
                        <img
                            src={image.url}
                            alt={image.original_name}
                            loading="lazy"
                            className="aspect-square w-full rounded-md bg-muted object-contain"
                        />
                        {image.is_primary && <Badge>Principal</Badge>}
                        {canManage && (
                            <div className="flex flex-wrap gap-1">
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    aria-label="Mover para antes"
                                    disabled={position === 0}
                                    onClick={() =>
                                        change(image, {
                                            direction: 'previous',
                                        })
                                    }
                                >
                                    <ArrowLeft />
                                </Button>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    aria-label="Mover para depois"
                                    disabled={position === images.length - 1}
                                    onClick={() =>
                                        change(image, { direction: 'next' })
                                    }
                                >
                                    <ArrowRight />
                                </Button>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    aria-label="Definir como principal"
                                    disabled={image.is_primary}
                                    onClick={() =>
                                        change(image, { is_primary: true })
                                    }
                                >
                                    <Star />
                                </Button>
                                <ConfirmButton
                                    size="sm"
                                    route={destroy({
                                        product: product.id,
                                        image: image.id,
                                    })}
                                    title="Excluir imagem"
                                    description="O arquivo será removido definitivamente."
                                    confirmLabel="Excluir"
                                >
                                    Excluir
                                </ConfirmButton>
                            </div>
                        )}
                        {canManage && product.type === 'variable' && (
                            <NativeSelect
                                aria-label="Variação da imagem"
                                value={String(image.product_variant_id ?? '')}
                                onChange={(event) =>
                                    change(image, {
                                        product_variant_id: event.target.value,
                                    })
                                }
                            >
                                <option value="">Todas as variações</option>
                                {variants.map((variant) => (
                                    <option key={variant.id} value={variant.id}>
                                        {variant.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
