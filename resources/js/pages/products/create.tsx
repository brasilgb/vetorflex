import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import ProductForm from '@/components/products/product-form';
import { create, index } from '@/routes/products';
import type { ProductFormOptions } from '@/types';

export default function CreateProduct(options: ProductFormOptions) {
    return (
        <>
            <Head title="Novo produto" />
            <div className="p-4 md:p-6">
                <Heading
                    title="Novo produto ou serviço"
                    description="Somente o nome é obrigatório; complete o cadastro quando precisar."
                />
                <ProductForm options={options} />
            </div>
        </>
    );
}

CreateProduct.layout = {
    breadcrumbs: [
        { title: 'Produtos', href: index() },
        { title: 'Novo', href: create() },
    ],
};
