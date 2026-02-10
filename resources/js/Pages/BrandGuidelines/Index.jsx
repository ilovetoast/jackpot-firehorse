import { usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'

export default function BrandGuidelinesIndex() {
  const { auth } = usePage().props

  return (
    <div className="min-h-screen flex flex-col bg-gray-50">
      <AppNav brand={auth?.activeBrand} tenant={null} />
      <main className="flex-1 py-12 px-4 sm:px-6 lg:px-8">
        <div className="max-w-3xl mx-auto">
          <h1 className="text-2xl font-bold text-gray-900 sm:text-3xl">
            Brand Guidelines
          </h1>
          <p className="mt-4 text-gray-600">
            This is a temporary placeholder. Brand guidelines content will be added here.
          </p>
        </div>
      </main>
    </div>
  )
}
