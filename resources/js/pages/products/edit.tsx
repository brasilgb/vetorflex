import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import ProductForm from '@/components/products/product-form';
import { index } from '@/routes/products';
import type { Product, ProductFormOptions, ProductVariant } from '@/types';

export default function EditProduct({
    product,
    variant,
    ...options
}: ProductFormOptions & { product: Product; variant: ProductVariant | null }) {
    return (
        <>
            <Head title={`Editar ${product.name}`} />
            <div className="p-4 md:p-6">
                <Heading title={product.name} description="Editar cadastro" />
                <ProductForm
                    product={product}
                    variant={variant}
                    options={options}
                />
            </div>
        </>
    );
}

EditProduct.layout = { breadcrumbs: [{ title: 'Produtos', href: index() }] };
