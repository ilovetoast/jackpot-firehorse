import AppNav from '../Components/AppNav'
import AppFooter from '../Components/AppFooter'

/**
 * Layout for authenticated admin pages (e.g. Deletion Errors).
 * Provides user, header slot, and children wrapped with AppNav and AppFooter.
 */
export default function AuthenticatedLayout({ user, header, children }) {
  return (
    <div className="min-h-screen flex flex-col bg-gray-50">
      <AppNav />
      {header && (
        <header className="bg-white shadow border-b border-gray-200">
          <div className="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8">
            {header}
          </div>
        </header>
      )}
      <main className="flex-1">
        {children}
      </main>
      <AppFooter />
    </div>
  )
}
