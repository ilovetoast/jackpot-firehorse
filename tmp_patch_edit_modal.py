from pathlib import Path
path = Path('resources/js/Components/Collections/EditCollectionModal.jsx')
t = path.read_text()
t = t.replace(
    "    LinkIcon,\n} from '@heroicons/react/24/outline'",
    "    LinkIcon,\n    EyeIcon,\n    EyeSlashIcon,\n} from '@heroicons/react/24/outline'",
)
needle = "    const [shareCopied, setShareCopied] = useState(false)\n    const [submitting, setSubmitting] = useState(false)"
repl = """    const [shareCopied, setShareCopied] = useState(false)
    const [showSharePassword, setShowSharePassword] = useState(false)
    const [shareEmailOpen, setShareEmailOpen] = useState(false)
    const [shareEmailTo, setShareEmailTo] = useState('')
    const [shareEmailNote, setShareEmailNote] = useState('')
    const [shareEmailIncludePassword, setShareEmailIncludePassword] = useState(false)
    const [shareEmailPassword, setShareEmailPassword] = useState('')
    const [showShareEmailPassword, setShowShareEmailPassword] = useState(false)
    const [shareEmailSending, setShareEmailSending] = useState(false)
    const [shareEmailError, setShareEmailError] = useState(null)
    const [shareEmailSent, setShareEmailSent] = useState(false)
    const [submitting, setSubmitting] = useState(false)"""
assert needle in t
t = t.replace(needle, repl, 1)
needle2 = """        setShareCopied(false)
        setError(null)"""
repl2 = """        setShareCopied(false)
        setShowSharePassword(false)
        setShareEmailOpen(false)
        setShareEmailTo('')
        setShareEmailNote('')
        setShareEmailIncludePassword(false)
        setShareEmailPassword('')
        setShowShareEmailPassword(false)
        setShareEmailSending(false)
        setShareEmailError(null)
        setShareEmailSent(false)
        setError(null)"""
assert needle2 in t
t = t.replace(needle2, repl2, 1)
path.write_text(t)
print('step1')
