import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'
import AppFooter from '../../Components/AppFooter'
import { DownloadsIndexContent } from './DownloadsIndexContent'

export default function DownloadsIndex(props) {
  return (
    <div className="min-h-screen flex flex-col bg-slate-50">
      <AppHead title="Downloads" />
      <AppNav />
      <main className="flex-1 py-6 px-4 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-7xl">
          <DownloadsIndexContent {...props} />
        </div>
      </main>
      <AppFooter />
    </div>
  )
}
